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

        // 1) 先查讨论（标题命中优先）
        $discussionIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();

        // 2) 再查帖子命中并映射到讨论
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postDiscussionIds = [];

        if ($postIds) {
            $postDiscussionIds = Post::query()
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();
        }

        // 3) 合并顺序：标题命中在前，正文命中在后
        $order = $discussionIds;
        foreach ($postDiscussionIds as $did) {
            if (!in_array($did, $order, true)) {
                $order[] = $did;
            }
        }

        if (!$order) {
            $search->getQuery()->whereRaw('0=1');
            return;
        }

        // 4) 约束 + 按自定义顺序排序
        $builder = $search->getQuery();

        if ($builder instanceof EloquentBuilder) {
            $model   = $builder->getModel();
            $conn    = $model->getConnection();
            $prefix  = $conn->getTablePrefix();         // e.g. flarum_
            $table   = $model->getTable();               // 可能是 discussions 或 flarum_discussions
            $pk      = $model->getKeyName();            // id

            // 去前缀版（给 WHERE 用，让语法器自动加一次）
            $tableNoPrefix = $table;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $tableNoPrefix . '.' . $pk;

            // 带前缀版（给 ORDER BY RAW 用，不会再被自动加前缀）
            $prefixedTable = $table;
            if ($prefix && strpos($prefixedTable, $prefix) !== 0) {
                $prefixedTable = $prefix . $prefixedTable;
            }
            $qualifiedForOrder = $prefixedTable . '.' . $pk;

            $builder->whereIn($qualifiedForWhere, $order);
            $placeholders = implode(',', array_fill(0, count($order), '?'));
            $builder->orderByRaw("FIELD($qualifiedForOrder, $placeholders)", $order);

        } elseif ($builder instanceof QueryBuilder) {
            $conn    = $builder->getConnection();
            $prefix  = $conn->getTablePrefix();
            $from    = $builder->from ?: (new Discussion())->getTable(); // 可能已带前缀
            $pk      = (new Discussion())->getKeyName();

            // 去前缀版（WHERE）
            $fromNoPrefix = $from;
            if ($prefix && strpos($fromNoPrefix, $prefix) === 0) {
                $fromNoPrefix = substr($fromNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $fromNoPrefix . '.' . $pk;

            // 带前缀版（ORDER BY RAW）
            $prefixedFrom = $from;
            if ($prefix && strpos($prefixedFrom, $prefix) !== 0) {
                $prefixedFrom = $prefix . $prefixedFrom;
            }
            $qualifiedForOrder = $prefixedFrom . '.' . $pk;

            $builder->whereIn($qualifiedForWhere, $order);
            $placeholders = implode(',', array_fill(0, count($order), '?'));
            $builder->orderByRaw("FIELD($qualifiedForOrder, $placeholders)", $order);

        } else {
            // 兜底：只做 WHERE，避免再出前缀问题
            $model   = new Discussion();
            $conn    = $model->getConnection();
            $prefix  = $conn->getTablePrefix();
            $table   = $model->getTable();
            $pk      = $model->getKeyName();

            $tableNoPrefix = $table;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $tableNoPrefix . '.' . $pk;

            try {
                $search->getQuery()->whereIn($qualifiedForWhere, $order);
            } catch (\Throwable $e) {
                $search->getQuery()->whereIn($pk, $order);
            }
        }
    }
}
