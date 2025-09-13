<?php

namespace LadyByron\ExactSearch;

use Laravel\Scout\Builder;
use Meilisearch\Client;
use Meilisearch\Scout\Engines\MeilisearchEngine as BaseMeilisearchEngine;

class ExactMeilisearchEngine extends BaseMeilisearchEngine
{
    public function __construct(Client $meilisearch)
    {
        parent::__construct($meilisearch);
    }

    protected function strict(array $options): array
    {
        // 所有分词必须命中
        $options['matchingStrategy'] = 'all';
        return $options;
    }

    protected function normalize(string $q): string
    {
        $q = trim($q);
        if ($q === '') return $q;

        // 已加引号或包含空白的不改
        if ((substr($q, 0, 1) === '"' && substr($q, -1) === '"') || preg_match('/\s/u', $q)) {
            return $q;
        }

        // 纯中文（2+汉字）自动变短语查询
        if (preg_match('/^[\x{4E00}-\x{9FFF}]{2,}$/u', $q)) {
            return '"' . $q . '"';
        }

        return $q;
    }

    public function search(Builder $builder)
    {
        $options = $this->strict($this->options($builder));
        $query   = $this->normalize($builder->query);

        return $this->meilisearch
            ->index($builder->index ?? $builder->model->searchableAs())
            ->search($query, $options);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $options = $this->strict($this->options($builder));
        $options['limit']  = (int) $perPage;
        $options['offset'] = max(0, ((int)$page - 1) * (int)$perPage);

        $query = $this->normalize($builder->query);

        return $this->meilisearch
            ->index($builder->index ?? $builder->model->searchableAs())
            ->search($query, $options);
    }
}
