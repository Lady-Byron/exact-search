<?php

use Flarum\Extend;
use LadyByron\ExactSearch\ExactScoutServiceProvider;
use LadyByron\ExactSearch\TitleFirstDiscussionGambit;
use LadyByron\ExactSearch\ApplyTitleFirstOnRelevance;
use Flarum\Discussion\Search\DiscussionSearcher;

return [
    // 注册我们对 Scout 的覆盖（Meilisearch 引擎 & 相关配置）
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),

    // 仅替换“全文检索解析器”为我们的 Gambit（负责限定命中集合，不做排序）
    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(TitleFirstDiscussionGambit::class),

    // 仅当 sort=relevance 时，追加“标题命中优先”的排序，不影响其它排序模式
    (new Extend\Event)
        ->listen(\Flarum\Discussion\Event\Searching::class, ApplyTitleFirstOnRelevance::class),
];
