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
            // 拿不到请求上下文就用默认值
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
            // ✅ 关键修复：不要手动拼接前缀！直接用逻辑表名 'posts'
            /** @var \Illuminate\Database\ConnectionInterface $conn */
            $conn = $builder->getConnection();

            // 也可以用下行更稳拿到逻辑表名（通常就是 'posts'）：
            // $postsTable = (new Post())->getTable();
            $postsTable = 'posts';

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
            // 没任何命中则不限定
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
        [$qualifiedForWhere, $qualifiedForOrder] = $this->resolveQualifiedIdColumns($builder);
        $builder->whereIn($qualifiedForWhere, $allIds);

        // ---- ORDER：把“本页应出现的 ID”排在最前；再剩余标题；再剩余正文 ----
        if (method_exists($builder, 'reorder')) {
            $builder->reorder();
        }

        $remainTitle = array_values(array_diff($titleIds, $pageIds));
        $remainBody  = array_values(array_diff($bodyIds,  $pageIds));

        $this->orderByFieldFirst($builder, $qualifiedForOrder, $pageIds);
        $this->orderByFieldFirst($builder, $qualifiedForOrder, $remainTitle);
        $this->orderByFieldFirst($builder, $qualifiedForOrder, $remainBody);
    }

    /**
     * 生成 “这些ID优先且按此顺序” 的 FIELD 排序两段：
     *   1) (FIELD(id, ...) = 0)  把不在列表内的放到后面
     *   2) FIELD(id, ...)        列表内按给定顺序
     */
    protected function orderByFieldFirst($builder, string $qualifiedId, array $ids): void
    {
        if (empty($ids)) return;

        $list = implode(',', array_map('intval', $ids));
        $builder->orderByRaw('(FIELD(' . $qualifiedId . ', ' . $list . ') = 0)');
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . $list . ')');
    }

    /**
     * 解析 WHERE/ORDER 应使用的 ID 列名（考虑 Eloquent/Query builder 以及表前缀）。
     * 返回数组：[$qualifiedForWhere, $qualifiedForOrder]
     */
    protected function resolveQualifiedIdColumns($builder): array
    {
        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();
            $qualified = method_exists($model, 'getQualifiedKeyName')
                ? $model->getQualifiedKeyName()
                : ($model->getTable() . '.' . $model->getKeyName());
            return [$qualified, $qualified];
        }

        if ($builder instanceof QueryBuilder) {
            $conn   = $builder->getConnection();
            $prefix = $conn->getTablePrefix();
            $from   = $builder->from ?: 'discussions';
            $tableNoPrefix = $from;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualified = $tableNoPrefix . '.id';
            return [$qualified, $qualified];
        }

        return ['id', 'id'];
    }
}
