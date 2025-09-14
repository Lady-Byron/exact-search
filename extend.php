<?php

use Flarum\Extend;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Api\Controller\ListDiscussionsController;
use LadyByron\ExactSearch\ExactScoutServiceProvider;
use LadyByron\ExactSearch\TitleFirstDiscussionGambit;
use LadyByron\ExactSearch\ApplyTitleFirstOnRelevance;

return [
    // 若你在用 Meilisearch 精确引擎，保留（可选）
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),

    // 用自定义 Gambit 限定“命中集合”
    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(TitleFirstDiscussionGambit::class),

    // 在搜索阶段尝试 SQL 排序（可能被其他扩展覆盖，不强依赖）
    (new Extend\Event)
        ->listen(\Flarum\Discussion\Event\Searching::class, ApplyTitleFirstOnRelevance::class),

    // —— 兜底关键：序列化前对结果做稳定重排（100% 生效）——
    (new Extend\ApiController(ListDiscussionsController::class))
        ->prepareDataForSerialization(ApplyTitleFirstOnRelevance::class),
];
