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
        // 仅当 sort=relevance 时生效
        $criteria = $event->criteria ?? null;
        $sort = $criteria->sort ?? null;
        if ($sort !== 'relevance') {
            return;
        }

        // 读取关键词
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

        // 标题命中的讨论ID
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));
        if (!$titleIds) {
            return; // 无标题命中就不加分组
        }

        $builder = $event->search->getQuery();

        // 可靠地拿到“实际 FROM 表名（含前缀/别名）”与主键名
        $pk = (new Discussion())->getKeyName();
        if ($builder instanceof EloquentBuilder) {
            $from = $builder->getQuery()->from ?: (new Discussion())->getTable(); // Eloquent 专用
        } elseif ($builder instanceof QueryBuilder) {
            $from = $builder->from ?: (new Discussion())->getTable();             // Query\Builder
        } else {
            $from = (new Discussion())->getTable();                               // 兜底
        }
        $qualifiedId = $from . '.' . $pk;

        // 组装 CASE 分组（标题命中优先）
        $inList = implode(',', $titleIds);
        $caseSql = "CASE WHEN {$qualifiedId} IN ($inList) THEN 0 ELSE 1 END";

        // 仅在 relevance 下重排；其它排序不受影响
        $builder->reorder();
        $builder->orderByRaw($caseSql);
        $builder->orderBy($from . '.last_posted_at', 'desc');
    }
}
