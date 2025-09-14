<?php

use Flarum\Extend;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Api\Controller\ListDiscussionsController;
use LadyByron\ExactSearch\ExactScoutServiceProvider;
use LadyByron\ExactSearch\TitleFirstDiscussionGambit;
use LadyByron\ExactSearch\ApplyTitleFirstOnRelevance;

return [
    // Meilisearch / Scout
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),

    // 只替换全文解析器：限定命中集合，不负责排序
    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(TitleFirstDiscussionGambit::class),

    // 事件阶段（SQL层面）先尝试按标题优先排序
    (new Extend\Event)
        ->listen(\Flarum\Discussion\Event\Searching::class, ApplyTitleFirstOnRelevance::class),

    // 兜底：控制器最后一步对已获取的数据做内存重排
    (new Extend\ApiController(ListDiscussionsController::class))
        ->prepareData([ApplyTitleFirstOnRelevance::class, 'prepare']),
];
