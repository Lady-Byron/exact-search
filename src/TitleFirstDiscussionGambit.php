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
     * 统一使用真实表名 + 主键，避免别名/前缀导致的列找不到问题。
     * 若你今后更改了前缀，只需改这两个常量。
     */
    private const ORDER_ID_COL = 'flarum_discussions.id';
    private const WHERE_ID_COL = 'flarum_discussions.id';

    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') {
            return;
        }

        /** @var EloquentBuilder|QueryBuilder $builder */
        $builder = $search->getQuery();

        // 读取分页参数
        $limit = 20;
        $offset = 0;
        try {
            /** @var ServerRequestInterface $req */
            $req = resolve(ServerRequestInterface::class);
            $p = $req->getQueryParams() ?? [];
            if (isset($p['page']['limit']))  $limit  = max(1, (int) $p['page']['limit']);
            if (isset($p['page']['offset'])) $offset = max(0, (int) $p['page']['offset']);
        } catch (\Throwable $e) {}

        /** @var \Illuminate\Database\ConnectionInterface $conn */
        $conn = $builder instanceof EloquentBuilder ? $builder->getQuery()->getConnection() : $builder->getConnection();
        $discTable = (new Discussion())->getTable(); // 'discussions'（由连接自动加前缀）
        $postTable = (new Post())->getTable();       // 'posts'
        $like = '%' . $this->escapeLike($q) . '%';

        // =========================================================
        // A) 标题命中：先用 Meili 排序，再用 DB LIKE 补全集（不丢条）
        // =========================================================
        $titleOrder = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleOrder = array_values(array_unique(array_map('intval', $titleOrder)));

        $titleAll = $conn->table($discTable)
            ->where('title', 'like', $like)
            ->pluck('id')
            ->all();
        $titleAll = array_values(array_unique(array_map('intval', $titleAll)));

        // 合并：先保持 Meili 的相关性顺序，再把 DB 漏掉的补到尾部
        $titleIds = array_values(array_unique(array_merge(
            $titleOrder,
            array_values(array_diff($titleAll, $titleOrder))
        )));

        // =========================================================
        // B) 正文命中：Meili + DB LIKE（仅评论贴），再去重
        // =========================================================
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postIds = array_values(array_unique(array_map('intval', $postIds)));

        $bodyIdsFromMeili = [];
        if ($postIds) {
            $rows = $conn->table($postTable)
                ->select('discussion_id')
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();
            $bodyIdsFromMeili = array_values(array_unique(array_map('intval', $rows)));
        }

        // —— DB 兜底正文（仅评论贴：type = 'comment'；避免系统事件贴）——
        // 说明：这一步是“子串包含”的兜底，确保不会丢“正文包含”的讨论。
        // 为控制负载，只取 discussion_id，不 JOIN，不取大字段。
        $bodyIdsFromDb = $conn->table($postTable)
            ->select('discussion_id')
            ->where('type', '=', 'comment')
            ->where('content', 'like', $like)
            ->pluck('discussion_id')
            ->all();
        $bodyIdsFromDb = array_values(array_unique(array_map('intval', $bodyIdsFromDb)));

        // 正文命中完整集合（先 Meili 的顺序，再把 DB 补到尾部）
        $bodyIdsFull = array_values(array_unique(array_merge(
            $bodyIdsFromMeili,
            array_values(array_diff($bodyIdsFromDb, $bodyIdsFromMeili))
        )));

        // 正文去掉已在标题中的（避免重复）
        $bodyIds = array_values(array_diff($bodyIdsFull, $titleIds));

        // =========================================================
        // C) 全集、分页前预排：第一页优先标题
        // =========================================================
        // 全集 = 标题全集 ∪ 正文全集（确保总条数与 DB LIKE 一致）
        $allIds = array_values(array_unique(array_merge($titleAll, $bodyIds)));
        if (!$allIds) {
            return;
        }

        // 按“标题优先”先切出当前页 ID
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

        // =========================================================
        // D) 下发 SQL：WHERE 限定全集；ORDER 先本页，再剩余标题，再剩余正文
        // =========================================================
        // WHERE：用真实列名 flarum_discussions.id（与 ORDER 保持一致，彻底规避别名问题）
        $builder->whereIn(self::WHERE_ID_COL, $allIds);

        // 清掉已有排序，使用我们的 FIELD 排序
        if (method_exists($builder, 'reorder')) {
            $builder->reorder();
        }

        $remainTitle = array_values(array_diff($titleIds, $pageIds));
        $remainBody  = array_values(array_diff($bodyIds,  $pageIds));

        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $pageIds);
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $remainTitle);
        $this->orderByFieldFirst($builder, self::ORDER_ID_COL, $remainBody);
    }

    /**
     * 把给定的 ID 列表顶到前面：
     * 1) (FIELD(col, ids) = 0) 升序，把不在列表内的排后；
     * 2) FIELD(col, ids) 升序，保证列表内部顺序。
     */
    protected function orderByFieldFirst($builder, string $rawIdCol, array $ids): void
    {
        if (empty($ids)) return;

        $list = implode(',', array_map('intval', $ids));

        /** @var QueryBuilder $qb */
        $qb = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        $qb->orderByRaw('(FIELD(' . $rawIdCol . ', ' . $list . ') = 0)');
        $qb->orderByRaw('FIELD(' . $rawIdCol . ', ' . $list . ')');
    }

    /**
     * LIKE 转义：避免用户查询里包含 %, _ 或反斜杠破坏匹配语义。
     */
    private function escapeLike(string $input): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $input);
    }
}
