<?php

namespace LadyByron\ExactSearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client;

class ExactMeilisearchEngine extends MeilisearchEngine
{
    public function __construct(Client $meilisearch)
    {
        parent::__construct($meilisearch);
    }

    protected function strict(array $options): array
    {
        $options['matchingStrategy'] = 'all';
        return $options;
    }

    protected function normalize(string $q): string
    {
        $q = trim($q);
        if ($q === '') return $q;

        // 已经是短语或含空白的不改
        if ((substr($q,0,1)==='"' && substr($q,-1)==='"') || preg_match('/\s/u',$q)) return $q;

        // 纯中文（2+汉字）→ 短语
        if (preg_match('/^[\x{4E00}-\x{9FFF}]{2,}$/u', $q)) return '"'.$q.'"';

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
