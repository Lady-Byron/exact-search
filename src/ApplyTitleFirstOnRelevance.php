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

class ApplyTitleFirstOnRelevance
{
    /** 事件阶段：尝试用 SQL FIELD() 排序 */
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

    /** 控制器阶段：对已取回的数据进行内存重排（兜底，保证生效） */
    public function prepare($controller, &$data, $request): void
    {
        $params = $request->getQueryParams();
        $q    = trim((string)($params['filter']['q'] ?? ''));
        $sort = $params['sort'] ?? null;

        if ($q === '' || !$this->isRelevance($sort)) {
            return;
        }

        [$orderedIds, $positions] = $this->buildOrder($q);
        if (!$orderedIds) {
            return;
        }

        // 支持 Collection 或 LengthAwarePaginator/Paginator
        if ($data instanceof AbstractPaginator) {
            $col = $this->reorderCollection($data->getCollection(), $positions);
            $data->setCollection($col);
        } elseif ($data instanceof Collection) {
            $data = $this->reorderCollection($data, $positions);
        }
    }

    /** 判断是否“相关推荐(relevance)”（含空 sort） */
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

    /** 生成“标题优先，其次正文”的 ID 顺序与位置表 */
    private function buildOrder(string $q): array
    {
        // 1) 标题命中
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 正文命中 -> discussion_id
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $bodyDiscussionIds = [];
        if ($postIds) {
            $map = Post::query()
                ->whereIn('id', $postIds)
                ->pluck('discussion_id', 'id');
            foreach ($postIds as $pid) {
                if (isset($map[$pid])) {
                    $bodyDiscussionIds[] = (int) $map[$pid];
                }
            }
            $bodyDiscussionIds = array_values(array_unique($bodyDiscussionIds));
        }

        if (!$titleIds && !$bodyDiscussionIds) {
            return [[], []];
        }

        $ordered = $titleIds;
        foreach ($bodyDiscussionIds as $did) {
            if (!in_array($did, $ordered, true)) {
                $ordered[] = $did;
            }
        }
        $ordered = array_slice($ordered, 0, 1000);
        $positions = array_flip($ordered);

        return [$ordered, $positions];
    }

    /** SQL层排序（多数情况下够用） */
    private function applySqlOrder($builder, string $q): void
    {
        [$ordered, ] = $this->buildOrder($q);
        if (!$ordered) return;

        $model = new Discussion();
        $pk    = $model->getKeyName();

        if ($builder instanceof EloquentBuilder) {
            $from = $builder->getQuery()->from ?: $model->getTable();
        } else { // QueryBuilder
            $from = $builder->from ?: $model->getTable();
        }
        $qualifiedId = $from . '.' . $pk;
        $list = implode(',', $ordered);

        // 先把不在列表里的行放到最后，再按列表顺序排前面
        $builder->reorder();
        $builder->orderByRaw('(FIELD(' . $qualifiedId . ', ' . $list . ') = 0)');
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . $list . ')');
    }

    /** 内存重排（保持未命中项的原始相对顺序，稳定排序） */
    private function reorderCollection(Collection $c, array $positions): Collection
    {
        // 记录原始位置，用作稳定排序的次级键
        $origIndex = [];
        foreach ($c->values() as $i => $m) {
            $origIndex[(int) $m->id] = $i;
        }

        return $c->sort(function ($a, $b) use ($positions, $origIndex) {
                $ida = (int) $a->id;
                $idb = (int) $b->id;
                $pa = $positions[$ida] ?? PHP_INT_MAX;
                $pb = $positions[$idb] ?? PHP_INT_MAX;

                if ($pa === $pb) {
                    // 稳定：保持未命中/同位置项的原始先后
                    return ($origIndex[$ida] <=> $origIndex[$idb]);
                }
                return $pa <=> $pb;
            })
            ->values();
    }
}
