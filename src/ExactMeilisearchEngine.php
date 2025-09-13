<?php

namespace LadyByron\ExactSearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Meilisearch\Client as Meili;

class ExactMeilisearchEngine extends Engine
{
    public function __construct(protected Meili $client) {}

    /* === 强制精确匹配：把中文连续字符串自动加引号，并一律用 matchingStrategy=all === */
    protected function tunedQuery(string $q = null): array
    {
        if ($q === null || $q === '') {
            return [null, []];
        }

        $query = $q;

        // 纯中文且没有空白：自动加一对引号，形成短语查询
        if (!str_contains($query, '"')
            && preg_match('/^\p{Han}+$/u', $query)     // 全是 CJK
        ) {
            $query = "\"{$query}\"";
        }

        // 统一把策略改成 'all'
        $options = ['matchingStrategy' => 'all'];

        return [$query, $options];
    }

    /* --- Scout 必需：search / paginate --- */
    public function search(Builder $builder)
    {
        [$q, $opts] = $this->tunedQuery((string) $builder->query);

        $index = $this->client->index($builder->index ?? $builder->model->searchableAs());

        // 合并调用方 options（若有）：
        $options = array_merge($builder->options ?? [], $opts);

        return $index->search($q, $options)->toArray();
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        [$q, $opts] = $this->tunedQuery((string) $builder->query);

        $index = $this->client->index($builder->index ?? $builder->model->searchableAs());

        $options = array_merge($builder->options ?? [], $opts, [
            'limit'  => (int) $perPage,
            'offset' => (int) (($page - 1) * $perPage),
        ]);

        return $index->search($q, $options)->toArray();
    }

    /* --- Scout 必需：把结果转回模型 / ID / 总数 --- */
    public function mapIds($results)
    {
        $hits = $results['hits'] ?? [];
        return collect($hits)->pluck('id');
    }

    public function map(Builder $builder, $results, $model)
    {
        $ids = $this->mapIds($results)->all();
        if (empty($ids)) {
            return $model->newCollection();
        }

        $keyName = $model->getKeyName();
        // 保持命中顺序
        $models = $model->whereIn($keyName, $ids)->get()->keyBy($keyName);
        return collect($ids)->map(fn ($id) => $models[$id] ?? null)->filter();
    }

    public function getTotalCount($results)
    {
        return (int) ($results['estimatedTotalHits'] ?? $results['nbHits'] ?? 0);
    }

    /* --- 你的 Scout 版本要求的额外抽象方法 --- */
    public function lazyMap(Builder $builder, $results, $model)
    {
        // 安全起见，直接复用 map 的行为
        return $this->map($builder, $results, $model);
    }

    /* --- 索引同步相关：最小实现即可 --- */
    public function update($models)
    {
        $index = $this->client->index($models->first()->searchableAs());
        $payload = $models->map->toSearchableArray()->values()->all();
        if (! empty($payload)) {
            $index->addDocuments($payload, $models->first()->getKeyName());
        }
    }

    public function delete($models)
    {
        $index = $this->client->index($models->first()->searchableAs());
        $ids   = $models->pluck($models->first()->getKeyName())->values()->all();
        if (! empty($ids)) {
            $index->deleteDocuments($ids);
        }
    }

    public function flush($model)
    {
        $this->client->index($model->searchableAs())->deleteAllDocuments();
    }
}
