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

        // 读取搜索词（兼容 query / q）
        $q = trim((string)($criteria->query ?? $criteria->q ?? ''));
        if ($q === '') {
            return; // 没有搜索词时不介入
        }

        // 仅在“相关推荐（relevance）”时介入：
        // 1) sort 为空（但有搜索词）=> 默认相关性
        // 2) 显式 sort=relevance 或 -relevance
        $sort = $criteria->sort ?? null;
        $isExplicitRelevance = false;

        if (is_string($sort)) {
            $isExplicitRelevance = (stripos($sort, 'relevance') !== false);
        } elseif (is_array($sort)) {
            // 兼容数组形式（如 ['-relevance'] 或 ['relevance' => 'desc']）
            $keysOrVals = array_merge(array_keys($sort), array_values($sort));
            $flat = implode(',', array_map('strval', $keysOrVals));
            $isExplicitRelevance = (stripos($flat, 'relevance') !== false);
        }

        $isImplicitRelevance = empty($sort); // 有搜索词但未指定 sort

        if (!($isExplicitRelevance || $isImplicitRelevance)) {
            return; // 其它排序（最新回复/最多点击等）一概不干预
        }

        // 1) 标题命中（discussions 索引仅含 title），保持 Meilisearch 的内部相关度顺序
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 正文命中（posts 索引 -> 映射到 discussion_id），保持 posts 的相关度顺序
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

        // 若两边都空，不干预
        if (!$titleIds && !$bodyDiscussionIds) {
            return;
        }

        // 3) 组合顺序：标题命中在前（保持其内部相关度），再接正文命中且不重复
        $orderedIds = $titleIds;
        foreach ($bodyDiscussionIds as $did) {
            if (!in_array($did, $orderedIds, true)) {
                $orderedIds[] = $did;
            }
        }
        // 防止 SQL 过长，保留前 1000 个
        $orderedIds = array_slice($orderedIds, 0, 1000);

        // 4) 仅重写相关推荐排序；不改变结果集合与其它分组逻辑
        $builder = $event->search->getQuery();

        // 可靠获取“from 表名（含前缀/别名）”与主键，避免 discussions.id / flarum_flarum_discussions.id 错误
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

        // 清空原有排序，只按我们的自定义顺序排（仍然只影响“相关推荐”分组）
        $builder->reorder();
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . implode(',', $orderedIds) . ')');
    }
}
