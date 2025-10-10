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

/**
 * 方案 A：分页感知 + 预排序 的 Fulltext Gambit
 *
 * 目标：当“标题命中 + 正文命中”混合时，保证**当前页**优先显示尽可能多的标题命中；
 * 若标题命中数 >= 本页容量，则本页全部都是标题命中。
 *
 * 做法：
 * 1) 读取 page[limit]/page[offset]；
 * 2) 计算：$titleIds（标题命中讨论ID，来自 Scout）、$bodyIds（正文命中映射为讨论ID，去掉已在标题中的）；
 * 3) 计算“本页应出现的 ID”$pageIds：先从 $titleIds 按 offset 切片补满，再用 $bodyIds 补齐；
 * 4) WHERE 限定到“全集合 = 标题∪正文”；ORDER BY 依次让 $pageIds、剩余标题、剩余正文 排在前面（用 FIELD()）。
 */
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
            // 容器里拿不到请求也没关系，使用默认值
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
            // 用一个轻量查询把帖子 ID 映射为讨论 ID；避免 N+1
            /** @var \Illuminate\Database\ConnectionInterface $conn */
            $conn = $builder->getConnection();
            $prefix = $conn->getTablePrefix();
            $posts = $prefix . 'posts';
            $rows = $conn->table($posts)->select('discussion_id')->whereIn('id', $postIds)->pluck('discussion_id')->all();
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

        // 注意：后续仍可能有其它扩展再追加排序，但我们的“桶排序”已把“本页应出现的 20 条”顶到最前，
        // 分页(LIMIT/OFFSET)发生之前顺序已经锁定。
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
        // Eloquent Builder：直接用模型提供的限定键名
        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();
            $qualified = method_exists($model, 'getQualifiedKeyName')
                ? $model->getQualifiedKeyName()
                : ($model->getTable() . '.' . $model->getKeyName());

            // 对于 ORDER / WHERE，使用相同的限定名最稳妥（前缀由语法器添加）
            return [$qualified, $qualified];
        }

        // Query Builder：从 from / 连接中推断
        if ($builder instanceof QueryBuilder) {
            $conn   = $builder->getConnection();
            $prefix = $conn->getTablePrefix();
            $from   = $builder->from ?: 'discussions';
            // 去掉已有前缀，交给语法器再次添加（避免双前缀）
            $tableNoPrefix = $from;
            if ($prefix && strpos($tableNoPrefix, $prefix) === 0) {
                $tableNoPrefix = substr($tableNoPrefix, strlen($prefix));
            }
            $qualified = $tableNoPrefix . '.id';
            return [$qualified, $qualified];
        }

        // 兜底
        return ['id', 'id'];
    }
}
