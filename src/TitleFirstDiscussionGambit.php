<?php

declare(strict_types=1);

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;

class TitleFirstDiscussionGambit implements GambitInterface
{
    // 你的实际库名：硬编码 ORDER BY 用的列，避免别名/前缀问题
    private const ORDER_ID_COL = 'flarum_discussions.id';

    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') return;

        /** @var EloquentBuilder|QueryBuilder $builder */
        $builder = $search->getQuery();

        // 读取分页参数
        $limit = 20; $offset = 0;
        try {
            /** @var ServerRequestInterface $req */
            $req = resolve(ServerRequestInterface::class);
            $p = $req->getQueryParams() ?? [];
            if (isset($p['page']['limit']))  $limit  = max(1, (int) $p['page']['limit']);
            if (isset($p['page']['offset'])) $offset = max(0, (int) $p['page']['offset']);
        } catch (\Throwable $e) {}

        // —— 收集命中 —— //
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        $postIds  = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postIds  = array_values(array_unique(array_map('intval', $postIds)));

        $bodyIds = [];
        if ($postIds) {
            /** @var \Illuminate\Database\ConnectionInterface $conn */
            $conn = $builder instanceof EloquentBuilder ? $builder->getQuery()->getConnection() : $builder->getConnection();
            // 直接用逻辑表名，让语法器加前缀
            $rows = $conn->table((new Post())->getTable())
                ->select('discussion_id')
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();
            $bodyIds = array_values(array_unique(array_map('intval', $rows)));
        }

        // 正文命中剔除已在标题命中的
        $bodyIds = array_values(array_diff($bodyIds, $titleIds));
        $allIds  = array_values(array_unique(array_merge($titleIds, $bodyIds)));
        if (!$allIds) return;

        // —— 当前页应出现的 ID（标题优先） —— //
        $pageIds = [];
        $titleCount = count($titleIds);
        if ($offset < $titleCount) {
            $fromTitle = array_slice($titleIds, $offset, $limit);
            $need      = $limit - count($fromTitle);
            $fromBody  = $need > 0 ? array_slice($bodyIds, 0, $need) : [];
            $pageIds   = array_values(array_unique(array_merge($fromTitle, $fromBody)));
        } else {
            $bodyOffset = $offset - $titleCount;
            $pageIds    = array_slice($bodyIds, $bodyOffset, $limit);
        }

        // —— WHERE：全集合（标题 ∪ 正文） —— //
        // 这里用 Eloquent 的限定名，让框架自己加前缀
        $whereIdCol = $builder instanceof EloquentBuilder
            ? $builder->getModel()->getTable() . '.' . $builder->getModel()->getKeyName() // discussions.id
            : 'discussions.id';
        $builder->whereIn($whereIdCol, $allIds);

        // —— ORDER：先本页，再剩余标题，再剩余正文 —— //
        if (method_exists($builder, 'reorder')) $builder->reorder();

        $remainTitle = array_values(array_diff($titleIds, $pageIds));
        $remainBody  = array_values(array_diff($bodyIds,  $pageIds));

        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $pageIds);
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $remainTitle);
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $remainBody);
    }

    /** 以固定列名生成 FIELD 排序两段 */
    protected function orderByFieldFirst($builder, string $rawIdCol, array $ids): void
    {
        if (empty($ids)) return;

        $list = implode(',', array_map('intval', $ids));
        // 用 Query\Builder 下发 RAW，避免 Eloquent 包装
        /** @var QueryBuilder $qb */
        $qb = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        // 注意：我们传入的列已是 flarum_discussions.id（硬对齐你的库名）
        $qb->orderByRaw('(FIELD(' . $rawIdCol . ', ' . $list . ') = 0)');
        $qb->orderByRaw('FIELD(' . $rawIdCol . ', ' . $list . ')');
    }
}
