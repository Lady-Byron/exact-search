<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;

class TitleFirstDiscussionGambit implements GambitInterface
{
    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') return;

        // 1) 先取“讨论索引”的命中（标题等），保持返回顺序
        $discussionIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();

        // 2) 再取“帖子索引”的命中 -> 映射为讨论 ID
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postDiscussionIds = [];
        if ($postIds) {
            // 只取还没出现过的讨论 ID
            $postDiscussionIds = Post::query()
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();
        }

        // 3) 合并：讨论命中优先，其次是仅正文命中的讨论
        $order = $discussionIds;
        foreach ($postDiscussionIds as $did) {
            if (!in_array($did, $order, true)) $order[] = $did;
        }

        if (!$order) {
            // 没有任何命中，返回空集合
            $search->getQuery()->whereRaw('0=1');
            return;
        }

        // 4) 约束并保持顺序（关键修复：用真实 FROM 表名 + 主键）
        $eloquent = $search->getQuery();           // Eloquent\Builder
        $base     = $eloquent->getQuery();         // Illuminate\Database\Query\Builder
        $from     = $base->from ?: $eloquent->getModel()->getTable(); // e.g. flarum_discussions
        $pk       = $eloquent->getModel()->getKeyName();              // e.g. id
        $qualifiedPk = $from . '.' . $pk;                              // e.g. flarum_discussions.id

        // 约束只取这批 ID
        $eloquent->whereIn($qualifiedPk, $order);

        // 用 FIELD(qualifiedPk, ?, ?, ...) 按命中顺序排序
        $placeholders = implode(',', array_fill(0, count($order), '?'));
        $eloquent->orderByRaw("FIELD($qualifiedPk, $placeholders)", $order);
    }
}
