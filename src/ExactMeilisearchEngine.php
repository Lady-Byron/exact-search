<?php

namespace LadyByron\ExactSearch;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Meilisearch\Client;

/**
 * 独立的 Meilisearch 引擎（不依赖具体适配包）。
 * 强制精确匹配：
 *   - matchingStrategy = 'all'
 *   - 纯中文且无空白 -> 自动包成 "短语"（相邻同序匹配）
 */
class ExactMeilisearchEngine extends Engine
{
    /** @var Client */
    protected $meilisearch;

    public function __construct(Client $meilisearch)
    {
        $this->meilisearch = $meilisearch;
    }

    /* ========== 索引同步 ========== */

    public function update($models)
    {
        /** @var Collection|Model[] $models */
        $models = $models instanceof Collection ? $models : Collection::make($models);
        if ($models->isEmpty()) return;

        $index   = $models->first()->searchableAs();
        $keyName = $models->first()->getScoutKeyName();

        $documents = $models->map(function (Model $model) use ($keyName) {
            $payload = array_filter($model->toSearchableArray(), static function ($v) {
                return $v !== null;
            });
            // 确保主键存在
            $payload[$keyName] = $model->getScoutKey();
            return $payload;
        })->values()->all();

        $this->meilisearch->index($index)->addDocuments($documents, $keyName);
    }

    public function delete($models)
    {
        /** @var Collection|Model[] $models */
        $models = $models instanceof Collection ? $models : Collection::make($models);
        if ($models->isEmpty()) return;

        $index = $models->first()->searchableAs();
        $ids   = $models->map->getScoutKey()->values()->all();

        $this->meilisearch->index($index)->deleteDocuments($ids);
    }

    public function flush($model)
    {
        // 清空所有文档，保留索引
        $this->meilisearch->index($model->searchableAs())->deleteAllDocuments();
    }

    public function createIndex($name, array $options = [])
    {
        $opts = [];
        if (isset($options['primaryKey'])) {
            $opts['primaryKey'] = $options['primaryKey'];
        }
        try {
            $this->meilisearch->createIndex($name, $opts ?: null);
        } catch (\Throwable $e) {
            // 忽略“已存在”
        }
    }

    public function deleteIndex($name)
    {
        try {
            $this->meilisearch->index($name)->delete();
        } catch (\Throwable $e) {
            // 忽略“不存在”
        }
    }

    /* ========== 搜索（关键逻辑） ========== */

    protected function strictOptions(array $options = []): array
    {
        // 所有分词必须命中
        $options['matchingStrategy'] = 'all';
        return $options;
    }

    protected function normalizeQuery(string $q): string
    {
        $q = trim($q);
        if ($q === '') return $q;

        // 已是短语或含空白 -> 不改
        if ((substr($q, 0, 1) === '"' && substr($q, -1) === '"') || preg_match('/\s/u', $q)) {
            return $q;
        }

        // 纯中文（>=2 汉字）-> 转为短语
        if (preg_match('/^[\x{4E00}-\x{9FFF}]{2,}$/u', $q)) {
            return '"' . $q . '"';
        }

        return $q;
    }

    private function resultToArray($res): array
    {
        if (is_array($res)) return $res;

        if (is_object($res)) {
            // meilisearch-php v1.5+ 的 SearchResult
            if (method_exists($res, 'toArray')) return $res->toArray();
            if (method_exists($res, 'getRaw'))  return $res->getRaw();

            $hits = method_exists($res, 'getHits') ? $res->getHits() : [];
            $est  = method_exists($res, 'getEstimatedTotalHits') ? $res->getEstimatedTotalHits() : null;
            $tot  = method_exists($res, 'getTotalHits') ? $res->getTotalHits() : null;
            return ['hits' => $hits, 'estimatedTotalHits' => $est, 'totalHits' => $tot];
        }

        return [];
    }

    public function search(Builder $builder)
    {
        $index   = $builder->index ?? $builder->model->searchableAs();
        $query   = $this->normalizeQuery((string) $builder->query);
        $options = $this->strictOptions($builder->options ?? []);

        // 简单等值 where -> Meili filter
        if (!empty($builder->wheres)) {
            $filters = [];
            foreach ($builder->wheres as $field => $value) {
                if (is_scalar($value)) {
                    $filters[] = $field . ' = "' . addcslashes((string) $value, '"') . '"';
                }
            }
            if ($filters) $options['filter'] = implode(' AND ', $filters);
        }

        $res = $this->meilisearch->index($index)->search($query, $options);
        return $this->resultToArray($res);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $index   = $builder->index ?? $builder->model->searchableAs();
        $query   = $this->normalizeQuery((string) $builder->query);
        $options = $this->strictOptions($builder->options ?? []);
        $options['limit']  = (int) $perPage;
        $options['offset'] = max(0, ((int) $page - 1) * (int) $perPage);

        if (!empty($builder->wheres)) {
            $filters = [];
            foreach ($builder->wheres as $field => $value) {
                if (is_scalar($value)) {
                    $filters[] = $field . ' = "' . addcslashes((string) $value, '"') . '"';
                }
            }
            if ($filters) $options['filter'] = implode(' AND ', $filters);
        }

        $res = $this->meilisearch->index($index)->search($query, $options);
        return $this->resultToArray($res);
    }

    /* ========== 结果映射 ========== */

    public function mapIds($results)
    {
        $arr  = $this->resultToArray($results);
        $hits = $arr['hits'] ?? [];
        if (!$hits) return collect();

        $first = $hits[0] ?? [];
        $key   = array_key_exists('id', $first)
            ? 'id'
            : (array_key_exists('objectID', $first) ? 'objectID' : null);

        if (!$key) return collect();

        return collect($hits)->pluck($key)->values();
    }

    public function map(Builder $builder, $results, $model)
    {
        $arr  = $this->resultToArray($results);
        $hits = $arr['hits'] ?? [];
        if (!$hits) return $model->newCollection();

        $ids = $this->mapIds($arr)->all();
        if (!$ids) return $model->newCollection();

        // 让 Scout 依自身逻辑取模型（保留作用域）
        $models = $model->getScoutModelsByIds($builder, $ids)->keyBy(function ($m) {
            return $m->getScoutKey();
        });

        // 保持 Meili 返回顺序
        return collect($ids)->map(function ($id) use ($models) {
            return $models->get($id);
        })->filter()->values();
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        // 兼容新版 Scout：以 LazyCollection 形式返回
        return LazyCollection::make(function () use ($builder, $results, $model) {
            foreach ($this->map($builder, $results, $model) as $item) {
                yield $item;
            }
        });
    }

    public function getTotalCount($results)
    {
        $arr = $this->resultToArray($results);
        return (int) ($arr['totalHits'] ?? $arr['estimatedTotalHits'] ?? $arr['nbHits'] ?? 0);
    }
}

