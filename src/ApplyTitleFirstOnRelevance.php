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
     * 签名为 ApiController::prepareDataForSerialization 的回调（按引用接收 $data）
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
        // 其它类型不处理
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
     * —— 核心改进点 ——
     * 生成“标题优先，其次正文”的 ID 顺序与位置表。
     * 为了避免极少数“标题含有关键词却被排到末端”的情况，
     * 这里在原有的 Meili 命中基础上，增加一次 **数据库标题 LIKE** 的补充，
     * 把所有“title LIKE %q%”的讨论优先合并到前面，保证真正的“标题命中”都靠前。
     */
    private function buildOrder(string $q): array
    {
        // A) 先取“标题命中（Meili 视角）”
        $titleIdsMeili = array_values(array_unique(array_map('intval',
            ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all()
        )));

        // B) 再取“正文命中 -> discussion_id”
        $postIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $bodyDiscussionIds = [];
        if ($postIds) {
            $map = Post::query()
                ->whereIn('id', array_map('intval', $postIds))
                ->pluck('discussion_id', 'id'); // key: post_id
            foreach ($postIds as $pid) {
                $pid = (int) $pid;
                if (isset($map[$pid])) {
                    $bodyDiscussionIds[] = (int) $map[$pid];
                }
            }
            $bodyDiscussionIds = array_values(array_unique($bodyDiscussionIds));
        }

        // C) 关键补充：用 DB 直接匹配 title LIKE "%phrase%"
        // 仅在“单段词（不含空白）且长度 2~32”时启用，避免对英文长查询过度扫表
        $titleIdsDb = $this->titleContainsIds($q);

        // D) 合并顺序：标题(明确 LIKE 命中) → 标题(引擎命中) → 正文(引擎命中)
        $ordered = $this->mergeIdsStable([$titleIdsDb, $titleIdsMeili, $bodyDiscussionIds]);

        if (!$ordered) {
            return [[], []];
        }

        // 控制长度，避免 FIELD 列表过大
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
     * 取出“明显的标题短语”并用 LIKE 匹配 title，返回命中 discussion id（最多 500）
     * 只在单段（不含空白）且长度 2~32 时启用，适配中文“连续词”场景（如“曹刘”）
     */
    private function titleContainsIds(string $rawQ): array
    {
        $phrase = $this->extractTitlePhraseCandidate($rawQ);
        if ($phrase === '') return [];

        $model = new Discussion();
        $table = $model->getTable();
        $conn  = $model->getConnection();

        // 转义 LIKE 特殊字符
        $like = '%' . strtr($phrase, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';

        // 使用 ESCAPE '\\'，兼容 MySQL/MariaDB，限制最多 500 条
        $ids = $conn->table($table)
            ->whereRaw("{$table}.title LIKE ? ESCAPE '\\\\'", [$like])
            ->limit(500)
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 从原查询中提取“适合用来匹配标题的短语”
     * - 去掉首尾引号
     * - 含空白则放弃（避免英文长句扫表）
     * - 长度限制 2~32
     */
    private function extractTitlePhraseCandidate(string $q): string
    {
        $q = trim($q);
        if ($q === '') return '';

        $first = substr($q, 0, 1);
        $last  = substr($q, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $q = substr($q, 1, -1);
            $q = trim($q);
        }

        // 含空白则放弃（避免过宽）
        if (preg_match('/\s/u', $q)) {
            return '';
        }

        // 长度 2~32
        if (function_exists('mb_strlen')) {
            $len = mb_strlen($q, 'UTF-8');
        } else {
            $len = strlen($q);
        }
        if ($len < 2 || $len > 32) {
            return '';
        }

        return $q;
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

    /**
     * 把若干 ID 列表按先后优先级合并为不重复的有序列表（稳定）
     */
    private function mergeIdsStable(array $lists): array
    {
        $seen = [];
        $out  = [];
        foreach ($lists as $list) {
            foreach ($list as $id) {
                $id = (int) $id;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $id;
                }
            }
        }
        return $out;
    }
}
