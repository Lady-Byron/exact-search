<?php

namespace LadyByron\ExactSearch;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Meilisearch\Client;

/**
 * Standalone Meilisearch engine that does NOT depend on meilisearch-laravel-scout.
 * It talks to Meilisearch via meilisearch-php directly.
 * We only enforce strict search behaviour in search/paginate.
 */
class ExactMeilisearchEngine extends Engine
{
    /** @var Client */
    protected $meilisearch;

    public function __construct(Client $meilisearch)
    {
        $this->meilisearch = $meilisearch;
    }

    /* =========================
       Indexing / Deleting data
       ========================= */

    public function update($models)
    {
        /** @var Collection|Model[] $models */
        $models = $models instanceof Collection ? $models : Collection::make($models);
        if ($models->isEmpty()) return;

        $index = $models->first()->searchableAs();
        $keyName = $models->first()->getScoutKeyName();

        $documents = $models->map(function (Model $model) use ($keyName) {
            $payload = array_filter($model->toSearchableArray(), static function ($v) {
                return $v !== null;
            });
            // Ensure primary key exists
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
        $ids = $models->map->getScoutKey()->values()->all();

        $this->meilisearch->index($index)->deleteDocuments($ids);
    }

    public function flush($model)
    {
        // Delete the entire index of the model
        $this->meilisearch->index($model->searchableAs())->delete();
    }

    public function createIndex($name, array $options = [])
    {
        $opts = [];
        if (isset($options['primaryKey'])) $opts['primaryKey'] = $options['primaryKey'];
        $this->meilisearch->createIndex($name, $opts ?: null);
    }

    public function deleteIndex($name)
    {
        $this->meilisearch->index($name)->delete();
    }

    /* =========================
       Searching (our special sauce)
       ========================= */

    protected function strictOptions(array $options = []): array
    {
        // Require all tokens to match
        $options['matchingStrategy'] = 'all';
        return $options;
    }

    protected function normalizeQuery(string $q): string
    {
        $q = trim($q);
        if ($q === '') return $q;

        // If already a phrase or contains whitespace, don't touch.
        if ((substr($q, 0, 1) === '"' && substr($q, -1) === '"') || preg_match('/\s/u', $q)) {
            return $q;
        }

        // Pure CJK (>=2 Han chars) => phrase search
        if (preg_match('/^[\x{4E00}-\x{9FFF}]{2,}$/u', $q)) {
            return '"' . $q . '"';
        }

        return $q;
    }

    public function search(Builder $builder)
    {
        $index = $builder->index ?? $builder->model->searchableAs();
        $query = $this->normalizeQuery($builder->query);
        $options = $this->strictOptions($builder->options);

        // Where filters from Builder (if any) -> turn into Meili filter syntax if simple equals
        if (!empty($builder->wheres)) {
            // Extremely simple equality-only support
            $filters = [];
            foreach ($builder->wheres as $field => $value) {
                if (is_scalar($value)) {
                    $filters[] = $field . ' = "' . addcslashes((string)$value, '"') . '"';
                }
            }
            if ($filters) $options['filter'] = implode(' AND ', $filters);
        }

        return $this->meilisearch->index($index)->search($query, $options);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $index = $builder->index ?? $builder->model->searchableAs();
        $query = $this->normalizeQuery($builder->query);
        $options = $this->strictOptions($builder->options);
        $options['limit']  = (int) $perPage;
        $options['offset'] = max(0, ((int)$page - 1) * (int)$perPage);

        if (!empty($builder->wheres)) {
            $filters = [];
            foreach ($builder->wheres as $field => $value) {
                if (is_scalar($value)) {
                    $filters[] = $field . ' = "' . addcslashes((string)$value, '"') . '"';
                }
            }
            if ($filters) $options['filter'] = implode(' AND ', $filters);
        }

        return $this->meilisearch->index($index)->search($query, $options);
    }

    public function mapIds($results)
    {
        $hits = $results['hits'] ?? [];
        if (!$hits) return collect();

        // Try common keys (id/primary key)
        $first = $hits[0] ?? [];
        $key = array_key_exists('id', $first) ? 'id' : (array_key_exists('objectID', $first) ? 'objectID' : null);
        if (!$key) return collect();

        return collect($hits)->pluck($key)->values();
    }

    public function map(Builder $builder, $results, $model)
    {
        $hits = $results['hits'] ?? [];
        if (!$hits) return $model->newCollection();

        $ids = $this->mapIds($results)->all();
        if (!$ids) return $model->newCollection();

        // Let Scout fetch models (respects custom scopes)
        $models = $model->getScoutModelsByIds($builder, $ids);

        // Keep the Meili order
        $models = $models->sortBy(function ($m) use ($ids) {
            return array_search($m->getScoutKey(), $ids, true);
        })->values();

        return $models;
    }

    public function getTotalCount($results)
    {
        return $results['estimatedTotalHits'] ?? $results['nbHits'] ?? 0;
    }
}
