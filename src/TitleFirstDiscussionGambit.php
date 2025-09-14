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

        // 1) 标题命中讨论ID
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 正文命中 -> 映射为讨论ID
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postDiscussionIds = [];
        if ($postIds) {
            $postDiscussionIds = Post::query()
                ->whereIn('id', array_map('intval', $postIds))
                ->pluck('discussion_id')
                ->all();
        }
        $postDiscussionIds = array_values(array_unique(array_map('intval', $postDiscussionIds)));

        // 3) 并集（确保正文命中也能出现）
        $allIds = array_values(array_unique(array_merge($titleIds, $postDiscussionIds)));

        if (!$allIds) {
            $search->getQuery()->whereRaw('0=1');
            return;
        }

        // 4) 仅限定命中集合；不添加任何排序（把“标题优先”交给后面的 Listener 在 relevance 时加入）
        $builder = $search->getQuery();

        if ($builder instanceof EloquentBuilder) {
            $model  = $builder->getModel();
            $conn   = $model->getConnection();
            $prefix = $conn->getTablePrefix();
            $table  = $model->getTable();
            $pk     = $model->getKeyName();

            // WHERE 用“去前缀”的表名，交给语法器自动再加一次前缀
            $tableNoPrefix = $table;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualifiedForWhere = $tableNoPrefix . '.' . $pk;

            $builder->whereIn($qualifiedForWhere, $allIds);

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

            $builder->whereIn($qualifiedForWhere, $allIds);

        } else {
            // 兜底：尽量不破坏查询，仅限定集合
            $model  = new Discussion();
            $conn   = $model->getConnection();
            $prefix = $conn->getTablePrefix();
            $table  = $model->getTable();
            $pk     = $model->getKeyName();

            $tableNoPrefix = $table;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }

            try {
                $search->getQuery()->whereIn($tableNoPrefix . '.' . $pk, $allIds);
            } catch (\Throwable $e) {
                $search->getQuery()->whereIn($pk, $allIds);
            }
        }
    }
}
