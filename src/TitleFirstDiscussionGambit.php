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
    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') {
            return;
        }

        /** @var EloquentBuilder|QueryBuilder $builder */
        $builder = $search->getQuery();

        // ---- 读取分页参数（默认 20 / 0）----
        $limit = 20;
        $offset = 0;
        try {
            /** @var ServerRequestInterface $req */
            $req = resolve(ServerRequestInterface::class);
            $params = $req->getQueryParams() ?? [];
            if (isset($params['page']['limit']))  $limit  = max(1, (int) $params['page']['limit']);
            if (isset($params['page']['offset'])) $offset = max(0, (int) $params['page']['offset']);
        } catch (\Throwable $e) {
            // 无请求上下文则用默认值
        }

        // ---- 收集命中 ID：标题命中、正文命中（映射为讨论）----
        // 1) 标题命中：Discussion 索引
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 正文命中：Post 索引 → discussion_id
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $postIds = array_values(array_unique(array_map('intval', $postIds)));

        $bodyIds = [];
        if ($postIds) {
            /** @var \Illuminate\Database\ConnectionInterface $conn */
            $conn = $builder->getConnection();

            // 用逻辑表名，交给语法器自动加前缀（避免 flarum_flarum_posts）
            $postsTable = (new Post())->getTable(); // 通常为 'posts'

            $rows = $conn->table($postsTable)
                ->select('discussion_id')
                ->whereIn('id', $postIds)
                ->pluck('discussion_id')
                ->all();

            $bodyIds = array_values(array_unique(array_map('intval', $rows)));
        }

        // 正文去掉已在标题中的
        $bodyIds = array_values(array_diff($bodyIds, $titleIds));
        $allIds  = array_values(array_unique(array_merge($titleIds, $bodyIds)));

        if (!$allIds) {
            return;
        }

        // ---- 计算“本页应出现的 ID”：优先标题命中 ----
        $pageIds = [];
        $titleCount = count($titleIds);

        if ($offset < $titleCount) {
            $fromTitle = array_slice($titleIds, $offset, $limit);
            $need      = $limit - count($fromTitle);
            $fromBody  = $need > 0 ? array_slice($bodyIds, 0, $need) : [];
            $pageIds   = array_values(array_unique(array_merge($fromTitle, $fromBody)));
        } else {
            // 已翻过所有标题命中；落到正文命中页
            $bodyOffset = $offset - $titleCount;
            $pageIds    = array_slice($bodyIds, $bodyOffset, $limit);
        }

        // ---- WHERE：限定到“全集合（标题∪正文）”----
        [$qualifiedForWhere, $qualifiedForOrderRaw] = $this->resolveIdColumns($builder);
        $builder->whereIn($qualifiedForWhere, $allIds);

        // ---- ORDER：把“本页应出现的 ID”排在最前；再剩余标题；再剩余正文 ----
        if (method_exists($builder, 'reorder')) {
            $builder->reorder();
        }

        $remainTitle = array_values(array_diff($titleIds, $pageIds));
        $remainBody  = array_values(array_diff($bodyIds,  $pageIds));

        $this->orderByFieldFirst($builder, $qualifiedForOrderRaw, $pageIds);
        $this->orderByFieldFirst($builder, $qualifiedForOrderRaw, $remainTitle);
        $this->orderByFieldFirst($builder, $qualifiedForOrderRaw, $remainBody);
    }

    /**
     * 为 ORDER BY RAW 专门返回“带前缀的 from 表名 + 主键”，例如 flarum_discussions.id
     * 同时也返回 WHERE 使用的“无前缀限定名”（如 discussions.id），交给语法器处理前缀。
     */
    protected function resolveIdColumns($builder): array
    {
        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();
            $qb    = $builder->getQuery();

            // WHERE 用限定名（无前缀），Laravel 会自动补前缀
            $whereQualified = method_exists($model, 'getQualifiedKeyName')
                ? $model->getQualifiedKeyName()                  // e.g. discussions.id
                : ($model->getTable() . '.' . $model->getKeyName());

            // ORDER RAW 需要**带前缀**的 from 表名
            $fromTable = $qb->from ?: $model->getTable();        // e.g. flarum_discussions
            $orderQualified = $fromTable . '.' . $model->getKeyName(); // flarum_discussions.id

            return [$whereQualified, $orderQualified];
        }

        if ($builder instanceof QueryBuilder) {
            $qb       = $builder;
            $from     = $qb->from ?: 'discussions'; // 这通常已带前缀（如 flarum_discussions）
            $orderQualified = $from . '.id';        // 用于 RAW（带前缀）
            // WHERE 用无前缀限定名，交给语法器处理
            $whereQualified = 'discussions.id';

            return [$whereQualified, $orderQualified];
        }

        // 兜底
        return ['discussions.id', 'discussions.id'];
    }

    /**
     * 生成 “这些ID优先且按此顺序” 的 FIELD 两段排序：
     *   1) (FIELD(tbl.id, ...) = 0)  把不在列表内的放到后面
     *   2) FIELD(tbl.id, ...)        列表内按给定顺序
     */
    protected function orderByFieldFirst($builder, string $qualifiedIdForRaw, array $ids): void
    {
        if (empty($ids)) return;

        $list = implode(',', array_map('intval', $ids));
        $builder->orderByRaw('(FIELD(' . $qualifiedIdForRaw . ', ' . $list . ') = 0)');
        $builder->orderByRaw('FIELD(' . $qualifiedIdForRaw . ', ' . $list . ')');
    }
}
