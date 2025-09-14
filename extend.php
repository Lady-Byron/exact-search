<?php

use Flarum\Extend;
use Flarum\Discussion\Search\DiscussionSearcher;
use LadyByron\ExactSearch\ExactScoutServiceProvider;
use LadyByron\ExactSearch\TitleFirstDiscussionGambit;
use LadyByron\ExactSearch\ApplyTitleFirstOnRelevance;

return [
    // 注册 Scout（Meilisearch）覆盖
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),

    // 只替换“全文解析器”，限定命中集合，不负责排序
    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(TitleFirstDiscussionGambit::class),

    // 只在 sort=relevance（或 sort 为空）时，追加“标题优先”的 SQL 排序
    (new Extend\Event)
        ->listen(\Flarum\Discussion\Event\Searching::class, ApplyTitleFirstOnRelevance::class),
];
