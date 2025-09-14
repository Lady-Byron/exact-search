<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Searching;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ApplyTitleFirstOnRelevance
{
    public function handle(Searching $event): void
    {
        // 仅当 sort= relevance 时生效
        $criteria = $event->criteria ?? null;
        $sort = $criteria->sort ?? null;
        if ($sort !== 'relevance') {
            return;
        }

        // 没有关键字就不处理
        $q = '';
        if (isset($criteria->query)) {
            $q = (string) $criteria->query;
        } elseif (isset($criteria->q)) {
            $q = (string) $criteria->q;
        }
        $q = trim($q);
        if ($q === '') {
            return;
        }

        // 取“标题命中”的讨论ID；用于构造 CASE 分组
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));
        if (!$titleIds) {
            return; // 没有标题命中，不需要加分组
        }

        $builder = $event->search->getQuery();

        // 计算到正确的表名和主键
        $qualifiedForOrder = null;
        if ($builder instanceof EloquentBuilder) {
            $model  = $builder->getModel();
            $conn   = $model->getConnection();
            $prefix = $conn->getTablePrefix();
            $table  = $model->getTable();
            if ($prefix && strpos($table, $prefix) !== 0) {
                $table = $prefix . $table; // raw 里需要带前缀
            }
            $pk = $model->getKeyName();
            $qualifiedForOrder = $table . '.' . $pk;

        } elseif ($builder instanceof QueryBuilder) {
            $conn   = $builder->getConnection();
            $prefix = $conn->getTablePrefix();
            $from   = $builder->from ?: (new Discussion())->getTable();
            if ($prefix && strpos($from, $prefix) !== 0) {
                $from = $prefix . $from;
            }
            $pk = (new Discussion())->getKeyName();
            $qualifiedForOrder = $from . '.' . $pk;

        } else {
            $model  = new Discussion();
            $conn   = $model->getConnection();
            $prefix = $conn->getTablePrefix();
            $table  = $model->getTable();
            if ($prefix && strpos($table, $prefix) !== 0) {
                $table = $prefix . $table;
            }
            $pk = $model->getKeyName();
            $qualifiedForOrder = $table . '.' . $pk;
        }

        // 用 int 拼接，避免注入
        $inList = implode(',', $titleIds);
        $caseSql = "CASE WHEN {$qualifiedForOrder} IN ($inList) THEN 0 ELSE 1 END";

        // relevance 下我们“定义优先级 + 兜底次级排序(last_posted_at desc)”
        // 采用 reorder() 确保 relevance 的排序完全按我们定义，不影响其它排序模式
        $builder->reorder();
        $builder->orderByRaw($caseSql);
        $builder->orderBy('last_posted_at', 'desc');
    }
}
