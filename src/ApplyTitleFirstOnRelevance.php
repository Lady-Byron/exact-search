<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Searching;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;

class ApplyTitleFirstOnRelevance
{
    public function handle(Searching $event): void
    {
        $criteria = $event->criteria ?? null;
        $q    = trim((string)($criteria->query ?? $criteria->q ?? ''));
        $sort = $criteria->sort ?? null;

        if ($q === '' || !$this->isRelevance($sort)) {
            return;
        }

        $builder = $event->search->getQuery();
        if ($builder instanceof EloquentBuilder || $builder instanceof QueryBuilder) {
            $this->applySqlOrder($builder, $q);
        }
    }

    public function __invoke($controller, &$data, ServerRequestInterface $request, $document): void
    {
        $params = $request->getQueryParams();
        $q    = trim((string)($params['filter']['q'] ?? ''));
        $sort = $params['sort'] ?? null;

        if ($q === '' || !$this->isRelevance($sort)) {
            return;
        }

        [, $positions] = $this->buildOrder($q);
        if (!$positions) {
            return;
        }

        if ($data instanceof AbstractPaginator) {
            $data->setCollection($this->reorderCollection($data->getCollection(), $positions));
            return;
        }

        if ($data instanceof Collection) {
            $data = $this->reorderCollection($data, $positions);
            return;
        }
    }

    private function isRelevance($sort): bool
    {
        if ($sort === null || $sort === '') return true;
        if (is_string($sort)) return stripos($sort, 'relevance') !== false;
        if (is_array($sort)) {
            $kv = array_merge(array_keys($sort), array_values($sort));
            return stripos(implode(',', array_map('strval', $kv)), 'relevance') !== false;
        }
        return false;
    }

    private function buildOrder(string $q): array
    {
        $titleIdsMeili = array_values(array_unique(array_map('intval',
            ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all()
        )));

        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $bodyDiscussionIds = [];
        if ($postIds) {
            $map = Post::query()
                ->whereIn('id', array_map('intval', $postIds))
                ->pluck('discussion_id', 'id');
            foreach ($postIds as $pid) {
                $pid = (int) $pid;
                if (isset($map[$pid])) {
                    $bodyDiscussionIds[] = (int) $map[$pid];
                }
            }
            $bodyDiscussionIds = array_values(array_unique($bodyDiscussionIds));
        }

        // —— 修复点：DB 层 LIKE 补强，确保所有“标题包含短语”的都靠前 ——
        $titleIdsDb = $this->titleContainsIds($q);

        // 合并优先级：标题(LIKE) → 标题(Meili) → 正文(Meili)
        $ordered = $this->mergeIdsStable([$titleIdsDb, $titleIdsMeili, $bodyDiscussionIds]);

        if (!$ordered) {
            return [[], []];
        }

        $ordered   = array_slice($ordered, 0, 1000);
        $positions = array_flip($ordered);

        return [$ordered, $positions];
    }

    private function applySqlOrder($builder, string $q): void
    {
        [$ordered, ] = $this->buildOrder($q);
        if (!$ordered) return;

        $model = new Discussion();
        $pk    = $model->getKeyName();

        $from = $builder instanceof EloquentBuilder
            ? ($builder->getQuery()->from ?: $model->getTable())
            : ($builder->from ?: $model->getTable());

        $qualifiedId = $from . '.' . $pk;
        $list = implode(',', $ordered);

        $builder->reorder();
        $builder->orderByRaw('(FIELD(' . $qualifiedId . ', ' . $list . ') = 0)');
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . $list . ')');
    }

    /**
     * 取出“适合用来匹配标题的短语”，并用 Eloquent 自动处理前缀地执行 LIKE
     * —— 关键修复：不再写 "{$table}.title"；只写列名 'title'，交给 Eloquent 加前缀/转义
     */
    private function titleContainsIds(string $rawQ): array
    {
        $phrase = $this->extractTitlePhraseCandidate($rawQ);
        if ($phrase === '') return [];

        // 转义 LIKE 特殊字符；MySQL 默认支持 '\' 作为转义（无需 ESCAPE 子句）
        $like = '%' . strtr($phrase, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';

        $ids = Discussion::query()
            ->where('title', 'LIKE', $like)
            ->limit(500)
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function extractTitlePhraseCandidate(string $q): string
    {
        $q = trim($q);
        if ($q === '') return '';

        $first = substr($q, 0, 1);
        $last  = substr($q, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $q = substr($q, 1, -1);
            $q = trim($q);
        }
        if (preg_match('/\s/u', $q)) {
            return '';
        }

        $len = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
        if ($len < 2 || $len > 32) {
            return '';
        }

        return $q;
    }

    private function reorderCollection(Collection $c, array $positions): Collection
    {
        $origIndex = [];
        foreach ($c->values() as $i => $m) {
            $origIndex[(int) $m->id] = $i;
        }

        return $c->sort(function ($a, $b) use ($positions, $origIndex) {
                $ida = (int) $a->id;
                $idb = (int) $b->id;
                $pa = $positions[$ida] ?? PHP_INT_MAX;
                $pb = $positions[$idb] ?? PHP_INT_MAX;
                return $pa === $pb ? ($origIndex[$ida] <=> $origIndex[$idb]) : ($pa <=> $pb);
            })
            ->values();
    }

    private function mergeIdsStable(array $lists): array
    {
        $seen = [];
        $out  = [];
        foreach ($lists as $list) {
            foreach ($list as $id) {
                $id = (int) $id;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $id;
                }
            }
        }
        return $out;
    }
}

