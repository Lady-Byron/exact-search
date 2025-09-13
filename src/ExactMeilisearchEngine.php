<?php

namespace LadyByron\ExactSearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client as MeiliClient;

class ExactMeilisearchEngine extends MeilisearchEngine
{
    public function __construct(MeiliClient $meilisearch)
    {
        parent::__construct($meilisearch);
    }

    /** Inject our strict options into every search. */
    protected function forceStrictOptions(array $options): array
    {
        // Require all tokens to match (prevents half-hits like only "曹" or only "刘")
        $options['matchingStrategy'] = 'all';
        return $options;
    }

    /** Turn pure-CJK queries into a phrase by adding quotes, unless user already used quotes. */
    protected function normalizeQuery(string $q): string
    {
        $trim = trim($q);

        // if already quoted OR contains any whitespace, don't wrap
        if ($trim === '' || ((substr($trim, 0, 1) === '"' && substr($trim, -1) === '"')) || preg_match('/\s/u', $trim)) {
            return $trim;
        }

        // Pure CJK (two or more Han characters) => phrase search
        if (preg_match('/^[\x{4E00}-\x{9FFF}]{2,}$/u', $trim)) {
            return '"' . $trim . '"';
        }

        return $trim;
    }

    /** Regular search path used by Scout. */
    public function search(Builder $builder)
    {
        $options = $this->forceStrictOptions($this->options($builder));
        $query   = $this->normalizeQuery($builder->query);

        return $this->meilisearch
            ->index($builder->index ?? $builder->model->searchableAs())
            ->search($query, $options);
    }

    /** Paginated search path used by Scout. */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $options = $this->forceStrictOptions($this->options($builder));
        $options['limit']  = (int) $perPage;
        $options['offset'] = max(0, ((int) $page - 1) * (int) $perPage);

        $query = $this->normalizeQuery($builder->query);

        return $this->meilisearch
            ->index($builder->index ?? $builder->model->searchableAs())
            ->search($query, $options);
    }
}
