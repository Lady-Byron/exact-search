<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Searching;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ApplyTitleFirstOnRelevance
{
    public function handle(Searching $event): void
    {
        $criteria = $event->criteria ?? null;

        // 读取搜索词
        $q = trim((string)($criteria->query ?? $criteria->q ?? ''));
        if ($q === '') {
            return;
        }

        // 只在“相关推荐（relevance）”时介入
        $sort = $criteria->sort ?? null;
        $isExplicitRelevance = false;

        if (is_string($sort)) {
            $isExplicitRelevance = (stripos($sort, 'relevance') !== false);
        } elseif (is_array($sort)) {
            $keysOrVals = array_merge(array_keys($sort), array_values($sort));
            $flat = implode(',', array_map('strval', $keysOrVals));
            $isExplicitRelevance = (stripos($flat, 'relevance') !== false);
        }
        $isImplicitRelevance = empty($sort);

        if (!($isExplicitRelevance || $isImplicitRelevance)) {
            // 最新回复、最多点击/回复等其它排序：完全不干预
            return;
        }

        // 1) 标题命中（discussions 索引）
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 正文命中（posts 索引 -> discussion_id），保持 posts 的相关度顺序
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
            return; // 两边都没命中，不干预
        }

        // 3) 合并顺序：标题命中在前（保留内部相关度），再接正文命中且不重复
        $orderedIds = $titleIds;
        foreach ($bodyDiscussionIds as $did) {
            if (!in_array($did, $orderedIds, true)) {
                $orderedIds[] = $did;
            }
        }
        $orderedIds = array_slice($orderedIds, 0, 1000); // 防止 SQL 过长
        $list = implode(',', $orderedIds);

        // 4) 只重排相关推荐的排序；不改变结果集合
        $builder = $event->search->getQuery();

        // 取到实际的 from（含前缀/别名）与主键，避免 discussions.id / flarum_flarum_discussions.id 错误
        $model = new Discussion();
        $pk    = $model->getKeyName();

        if ($builder instanceof EloquentBuilder) {
            $from = $builder->getQuery()->from ?: $model->getTable();
        } elseif ($builder instanceof QueryBuilder) {
            $from = $builder->from ?: $model->getTable();
        } else {
            $from = $model->getTable();
        }
        $qualifiedId = $from . '.' . $pk;

        // 关键：不 whereIn，只重排。
        // 为了把“未在列表中的行”(FIELD=0) 放到最后，同时保持列表内部顺序，
        // 使用两段排序：(FIELD(...) = 0), FIELD(...)
        $builder->reorder();
        $builder->orderByRaw('(FIELD(' . $qualifiedId . ', ' . $list . ') = 0)');
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . $list . ')');
    }
}
