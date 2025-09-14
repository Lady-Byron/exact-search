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

        // 1) 取标题命中讨论ID（优先级高）
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 取帖子命中ID，映射为讨论ID（优先级低）
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postDiscussionIds = [];
        if ($postIds) {
            $postDiscussionIds = Post::query()
                ->whereIn('id', array_map('intval', $postIds))
                ->pluck('discussion_id')
                ->all();
        }
        $postDiscussionIds = array_values(array_unique(array_map('intval', $postDiscussionIds)));

        // 3) 搜索结果 = 两组并集（保证正文命中也能出现）
        $allIds = array_values(array_unique(array_merge($titleIds, $postDiscussionIds)));

        // 若空，直接返回空结果
        if (!$allIds) {
            $search->getQuery()->whereRaw('0=1');
            return;
        }

        // 4) 仅做“标题优先的分组排序”，其余排序交给核心/插件
        $builder = $search->getQuery();

        if ($builder instanceof EloquentBuilder) {
            $model  = $builder->getModel();
            $conn   = $model->getConnection();
            $prefix = $conn->getTablePrefix();          // 例如 flarum_
            $table  = $model->getTable();               // 可能已带前缀
            $pk     = $model->getKeyName();             // id

            // WHERE 用“去前缀”的表名，交给语法器自动加一次前缀
            $tableNoPrefix = $table;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $tableNoPrefix . '.' . $pk;

            // ORDER BY RAW 用“已带前缀”的表名（raw 不会再自动加前缀）
            $prefixedTable = $table;
            if ($prefix && strpos($prefixedTable, $prefix) !== 0) {
                $prefixedTable = $prefix . $prefixedTable;
            }
            $qualifiedForOrder = $prefixedTable . '.' . $pk;

            // 先限定搜索集合
            $builder->whereIn($qualifiedForWhere, $allIds);

            // 仅当存在标题命中时才增加“标题优先”的分组排序
            if ($titleIds) {
                $placeholders = implode(',', array_fill(0, count($titleIds), '?'));
                // 0 在前（标题命中），1 在后（仅正文命中）
                $builder->orderByRaw("CASE WHEN $qualifiedForOrder IN ($placeholders) THEN 0 ELSE 1 END", $titleIds);
            }

        } elseif ($builder instanceof QueryBuilder) {
            $conn   = $builder->getConnection();
            $prefix = $conn->getTablePrefix();
            $from   = $builder->from ?: (new Discussion())->getTable();
            $pk     = (new Discussion())->getKeyName();

            $fromNoPrefix = $from;
            if ($prefix && strpos($fromNoPrefix, $prefix) === 0) {
                $fromNoPrefix = substr($fromNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $fromNoPrefix . '.' . $pk;

            $prefixedFrom = $from;
            if ($prefix && strpos($prefixedFrom, $prefix) !== 0) {
                $prefixedFrom = $prefix . $prefixedFrom;
            }
            $qualifiedForOrder = $prefixedFrom . '.' . $pk;

            $builder->whereIn($qualifiedForWhere, $allIds);

            if ($titleIds) {
                $placeholders = implode(',', array_fill(0, count($titleIds), '?'));
                $builder->orderByRaw("CASE WHEN $qualifiedForOrder IN ($placeholders) THEN 0 ELSE 1 END", $titleIds);
            }

        } else {
            // 兜底：尽量不破坏排序，只限定集合
            $model  = new Discussion();
            $conn   = $model->getConnection();
            $prefix = $conn->getTablePrefix();
            $table  = $model->getTable();
            $pk     = $model->getKeyName();

            $tableNoPrefix = $table;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $tableNoPrefix . '.' . $pk;

            try {
                $search->getQuery()->whereIn($qualifiedForWhere, $allIds);
            } catch (\Throwable $e) {
                $search->getQuery()->whereIn($pk, $allIds);
            }
            // 不加任何 orderByRaw，交给后续排序器处理
        }
    }
}
