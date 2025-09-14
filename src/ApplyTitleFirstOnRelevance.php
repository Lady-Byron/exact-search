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
        // 仅在相关推荐（relevance）时介入
        $criteria = $event->criteria ?? null;
        if (($criteria->sort ?? null) !== 'relevance') {
            return;
        }

        // 读取搜索词
        $q = (string)($criteria->query ?? $criteria->q ?? '');
        $q = trim($q);
        if ($q === '') {
            return;
        }

        // 1) 标题命中（discussions 索引仅含 title），顺序即 Meilisearch 相关度顺序
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

        // 若两边都空，就不干预
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
        // 避免 SQL 过长，保守截断到前 1000 个
        $orderedIds = array_slice($orderedIds, 0, 1000);

        $builder = $event->search->getQuery();

        // 可靠获取“实际 from 表名（含前缀/别名）”与主键
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

        // 4) 仅重写相关推荐的排序；不添加任何次级排序，保持“相关度内的原生顺序”
        $builder->reorder();
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . implode(',', $orderedIds) . ')');
    }
}
