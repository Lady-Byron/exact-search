<?php

use Flarum\Extend;
use LadyByron\ExactSearch\ExactScoutServiceProvider;
use LadyByron\ExactSearch\TitleFirstDiscussionGambit;
use Flarum\Discussion\Search\DiscussionSearcher;

return [
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),

    // 让“讨论搜索”的全文解析使用我们自定义的合并策略（标题优先，其次正文）
    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(TitleFirstDiscussionGambit::class),
];
