<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Searching;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;

class ApplyTitleFirstOnRelevance
{
    /**
     * 事件阶段：尝试 SQL 排序（可能被后续扩展覆盖，真正兜底在 __invoke 里）
     */
    public function handle(Searching $event): void
    {
        $criteria = $event->criteria ?? null;
        $q    = trim((string)($criteria->query ?? $criteria->q ?? ''));
        $sort = $criteria->sort ?? null;

        if ($q === '' || !$this->isRelevance($sort)) {
            return;
        }

        $builder = $event->search->getQuery();
        if ($builder instanceof EloquentBuilder || $builder instanceof QueryBuilder) {
            $this->applySqlOrder($builder, $q);
        }
    }

    /**
     * 兜底阶段：控制器序列化前对数据进行稳定重排（保证 100% 生效）
     * 签名为 ApiController::prepareDataForSerialization 的回调：
     * function ($controller, array|Collection|Paginator &$data, ServerRequestInterface $request, Document $document)
     */
    public function __invoke($controller, &$data, ServerRequestInterface $request, $document): void
    {
        $params = $request->getQueryParams();
        $q    = trim((string)($params['filter']['q'] ?? ''));
        $sort = $params['sort'] ?? null;

        if ($q === '' || !$this->isRelevance($sort)) {
            return;
        }

        [, $positions] = $this->buildOrder($q);
        if (!$positions) {
            return;
        }

        if ($data instanceof AbstractPaginator) {
            $data->setCollection($this->reorderCollection($data->getCollection(), $positions));
            return;
        }

        if ($data instanceof Collection) {
            $data = $this->reorderCollection($data, $positions);
            return;
        }

        // 若是数组/其它类型，保持安全不处理
    }

    /**
     * 判断是否“相关推荐(relevance)”（含空 sort）
     */
    private function isRelevance($sort): bool
    {
        if ($sort === null || $sort === '') return true;
        if (is_string($sort)) return stripos($sort, 'relevance') !== false;
        if (is_array($sort)) {
            $kv = array_merge(array_keys($sort), array_values($sort));
            return stripos(implode(',', array_map('strval', $kv)), 'relevance') !== false;
        }
        return false;
    }

    /**
     * 生成“标题优先，其次正文”的 ID 顺序与位置表
     */
    private function buildOrder(string $q): array
    {
        // 1) 标题命中
        $titleIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        // 2) 正文命中 -> discussion_id
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $bodyDiscussionIds = [];
        if ($postIds) {
            $map = Post::query()
                ->whereIn('id', array_map('intval', $postIds))
                ->pluck('discussion_id', 'id'); // key: post_id, value: discussion_id

            foreach ($postIds as $pid) {
                $pid = (int) $pid;
                if (isset($map[$pid])) {
                    $bodyDiscussionIds[] = (int) $map[$pid];
                }
            }
            $bodyDiscussionIds = array_values(array_unique($bodyDiscussionIds));
        }

        if (!$titleIds && !$bodyDiscussionIds) {
            return [[], []];
        }

        // 标题优先 + 合并正文命中
        $ordered = $titleIds;
        foreach ($bodyDiscussionIds as $did) {
            if (!in_array($did, $ordered, true)) {
                $ordered[] = $did;
            }
        }

        // 限制长度，避免巨大的 FIELD 列表
        $ordered   = array_slice($ordered, 0, 1000);
        $positions = array_flip($ordered);

        return [$ordered, $positions];
    }

    /**
     * SQL 层排序（事件阶段尝试，可能被覆盖）
     */
    private function applySqlOrder($builder, string $q): void
    {
        [$ordered, ] = $this->buildOrder($q);
        if (!$ordered) return;

        $model = new Discussion();
        $pk    = $model->getKeyName();

        $from = $builder instanceof EloquentBuilder
            ? ($builder->getQuery()->from ?: $model->getTable())
            : ($builder->from ?: $model->getTable());

        $qualifiedId = $from . '.' . $pk;
        $list = implode(',', $ordered); // 主键为 int，无需引号

        $builder->reorder(); // 清除已有 order by
        $builder->orderByRaw('(FIELD(' . $qualifiedId . ', ' . $list . ') = 0)');
        $builder->orderByRaw('FIELD(' . $qualifiedId . ', ' . $list . ')');
    }

    /**
     * 内存稳定重排（保持未命中项原相对顺序）
     */
    private function reorderCollection(Collection $c, array $positions): Collection
    {
        $origIndex = [];
        foreach ($c->values() as $i => $m) {
            $origIndex[(int) $m->id] = $i;
        }

        return $c->sort(function ($a, $b) use ($positions, $origIndex) {
                $ida = (int) $a->id;
                $idb = (int) $b->id;
                $pa = $positions[$ida] ?? PHP_INT_MAX;
                $pb = $positions[$idb] ?? PHP_INT_MAX;
                return $pa === $pb ? ($origIndex[$ida] <=> $origIndex[$idb]) : ($pa <=> $pb);
            })
            ->values();
    }
}
