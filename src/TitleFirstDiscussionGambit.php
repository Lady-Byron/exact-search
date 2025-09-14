<?php

namespace LadyByron\ExactSearch;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;

class TitleFirstDiscussionGambit implements GambitInterface
{
    public function apply(SearchState $search, $bit)
    {
        $q = trim((string) $bit);
        if ($q === '') return;

        // 1) 先拿“讨论索引”的命中（标题等），保持返回顺序
        $dIds = ScoutStatic::makeBuilder(Discussion::class, $q)->keys()->all();

        // 2) 再拿“帖子索引”的命中 -> 映射为讨论 ID
        $pIds = ScoutStatic::makeBuilder(Post::class, $q)->keys()->all();
        $pDid = [];
        if ($pIds) {
            $pDid = Post::query()->whereIn('id', $pIds)->pluck('discussion_id')->all();
        }

        // 3) 合并：讨论命中优先，其次是仅正文命中的讨论
        $order = $dIds;
        foreach ($pDid as $did) {
            if (!in_array($did, $order, true)) $order[] = $did;
        }

        if (!$order) {
            $search->getQuery()->whereRaw('0=1');
            return;
        }

        // 4) 约束并保持顺序
        $qBuilder = $search->getQuery();
        $qBuilder->whereIn('discussions.id', $order);

        $placeholders = implode(',', array_fill(0, count($order), '?'));
        $qBuilder->orderByRaw('FIELD(discussions.id, '.$placeholders.')', $order);
    }
}
