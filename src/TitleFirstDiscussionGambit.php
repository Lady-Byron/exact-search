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
    /**
     * 为了避免出现 "Unknown column 'discussions.id'" 的报错，
     * 这里把 ORDER BY 用到的列名写成数据库里的**真实表名**（含前缀）+ 主键。
     * 你的前缀是 flarum_，讨论表是 flarum_discussions，主键是 id。
     * 若以后前缀改了，只需改这一行即可。
     */
    private const ORDER_ID_COL = 'flarum_discussions.id';

    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') {
            return;
        }

        /** @var EloquentBuilder|QueryBuilder $builder */
        $builder = $search->getQuery();

        // 读取分页参数（page[limit]/page[offset]）
        $limit = 20;
        $offset = 0;
        try {
            /** @var ServerRequestInterface $req */
            $req = resolve(ServerRequestInterface::class);
            $p = $req->getQueryParams() ?? [];
            if (isset($p['page']['limit'])) {
                $limit = max(1, (int) $p['page']['limit']);
            }
            if (isset($p['page']['offset'])) {
                $offset = max(0, (int) $p['page']['offset']);
            }
        } catch (\Throwable $e) {
            // 忽略读取失败，沿用默认
        }

        // 拿到连接与逻辑表名（让框架负责自动加前缀）
        /** @var \Illuminate\Database\ConnectionInterface $conn */
        $conn = $builder instanceof EloquentBuilder ? $builder->getQuery()->getConnection() : $builder->getConnection();
        $discTable = (new Discussion())->getTable(); // 'discussions'
        $postTable = (new Post())->getTable();       // 'posts'

        // ------------------------
        // A) 标题命中
        // ------------------------

        // A1) 标题“排序用”的列表：使用 Scout（相关性好，但可能被引擎截断）
        $titleOrder = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleOrder = array_values(array_unique(array_map('intval', $titleOrder)));

        // A2) 标题“全集”：用 DB 的 LIKE 做兜底，绝不丢任何“子串包含”
        $titleAll = $conn->table($discTable)
            ->where('title', 'like', '%' . $q . '%')
            ->pluck('id')
            ->all();
        $titleAll = array_values(array_unique(array_map('intval', $titleAll)));

        // A3) 把“排序用列表”与“全集”合并：先按 Scout 的顺序，再把 DB 漏掉的补到尾部
        $titleIds = array_values(array_unique(array_merge(
            $titleOrder,
            array_values(array_diff($titleAll, $titleOrder))
        )));

        // ------------------------
        // B) 正文命中（posts -> discussion_id）
        // ------------------------
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postIds = array_values(array_unique(array_map('intval', $postIds)));

        $bodyIds = [];
        if ($postIds) {
            $rows = $conn->table($postTable)
                ->select('discussion_id')
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();
            $bodyIds = array_values(array_unique(array_map('intval', $rows)));
        }

        // 正文命中去掉已在标题命中的
        $bodyIds = array_values(array_diff($bodyIds, $titleIds));

        // —— “全集”用于 WHERE IN（保证总条数与 DB LIKE 一致） —— //
        $allIds = array_values(array_unique(array_merge($titleAll, $bodyIds)));
        if (!$allIds) {
            return;
        }

        // ------------------------
        // C) 先按“标题优先”切出当前页的 ID（分页前预排）
        // ------------------------
        $pageIds = [];
        $titleCount = count($titleIds);
        if ($offset < $titleCount) {
            $fromTitle = array_slice($titleIds, $offset, $limit);
            $need = $limit - count($fromTitle);
            $fromBody = $need > 0 ? array_slice($bodyIds, 0, $need) : [];
            $pageIds = array_values(array_unique(array_merge($fromTitle, $fromBody)));
        } else {
            // 标题命中已经用尽，从正文命中里继续
            $bodyOffset = $offset - $titleCount;
            $pageIds = array_slice($bodyIds, $bodyOffset, $limit);
        }

        // ------------------------
        // D) WHERE：限定到全集；ORDER：先本页，再剩余标题，最后剩余正文
        // ------------------------

        // WHERE 用“模型表名.主键”，让框架自动加前缀
        $whereIdCol = $builder instanceof EloquentBuilder
            ? $builder->getModel()->getTable() . '.' . $builder->getModel()->getKeyName()
            : 'discussions.id';
        $builder->whereIn($whereIdCol, $allIds);

        // 先清掉已有排序（如 last_posted_at），再加我们的 FIELD 排序
        if (method_exists($builder, 'reorder')) {
            $builder->reorder();
        }

        $remainTitle = array_values(array_diff($titleIds, $pageIds));
        $remainBody  = array_values(array_diff($bodyIds,  $pageIds));

        // 使用真实库名列 flarum_discussions.id 做 ORDER BY，避免别名/前缀问题
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $pageIds);
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $remainTitle);
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $remainBody);
    }

    /**
     * 把给定的 ID 列表顶到前面：
     * 1) 先按 (FIELD(col, ids) = 0) 升序，把不在列表内的排后；
     * 2) 再按 FIELD(col, ids) 升序，保证列表内部的顺序。
     */
    protected function orderByFieldFirst($builder, string $rawIdCol, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $list = implode(',', array_map('intval', $ids));

        /** @var QueryBuilder $qb */
        $qb = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;

        $qb->orderByRaw('(FIELD(' . $rawIdCol . ', ' . $list . ') = 0)');
        $qb->orderByRaw('FIELD(' . $rawIdCol . ', ' . $list . ')');
    }
}


