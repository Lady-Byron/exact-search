<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class TitleFirstDiscussionGambit implements GambitInterface
{
    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') {
            return;
        }

        // 1) 先拿“讨论索引”的命中（标题等），保持返回顺序
        $discussionIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();

        // 2) 再拿“帖子索引”的命中 -> 映射为讨论 ID
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postDiscussionIds = [];

        if ($postIds) {
            $postDiscussionIds = Post::query()
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();
        }

        // 3) 合并：讨论命中优先，其次是仅正文命中的讨论
        $order = $discussionIds;
        foreach ($postDiscussionIds as $did) {
            if (!in_array($did, $order, true)) {
                $order[] = $did;
            }
        }

        if (!$order) {
            // 没有任何命中，强制返回空集合
            $search->getQuery()->whereRaw('0=1');
            return;
        }

        // 4) 约束并保持顺序 —— 兼容 Eloquent\Builder 和 Query\Builder
        $builder = $search->getQuery(); // 可能是 Eloquent\Builder 或 Query\Builder

        if ($builder instanceof EloquentBuilder) {
            $table = $builder->getModel()->getTable();   // 如 flarum_discussions
            $pk    = $builder->getModel()->getKeyName(); // 一般是 id
            $qualifiedPk = $table . '.' . $pk;

            $builder->whereIn($qualifiedPk, $order);

            $placeholders = implode(',', array_fill(0, count($order), '?'));
            $builder->orderByRaw("FIELD($qualifiedPk, $placeholders)", $order);

        } elseif ($builder instanceof QueryBuilder) {
            // 已是底层查询构建器：直接使用
            $table = $builder->from ?: (new Discussion())->getTable();
            $pk    = (new Discussion())->getKeyName();
            $qualifiedPk = $table . '.' . $pk;

            $builder->whereIn($qualifiedPk, $order);

            $placeholders = implode(',', array_fill(0, count($order), '?'));
            $builder->orderByRaw("FIELD($qualifiedPk, $placeholders)", $order);

        } else {
            // 极端情况：无法识别类型，至少加一个 whereIn 兜底（不排序）
            $table = (new Discussion())->getTable();
            $pk    = (new Discussion())->getKeyName();
            $qualifiedPk = $table . '.' . $pk;

            // 尝试以最通用方式限制集合
            try {
                $search->getQuery()->whereIn($qualifiedPk, $order);
            } catch (\Throwable $e) {
                $search->getQuery()->whereIn($pk, $order);
            }
        }
    }
}

