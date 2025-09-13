<?php

namespace LadyByron\ExactSearch;

use Flarum\Foundation\AbstractServiceProvider;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeiliClient;

class ExactScoutServiceProvider extends AbstractServiceProvider
{
    public function boot(EngineManager $engines)
    {
        // 用同名覆盖 'meilisearch' 驱动，避免要求在后台改“驱动”下拉框
        $engines->extend('meilisearch', function ($app) {
            // 直接用 meilisearch-php 客户端，避免依赖某个“具体 Engine 类”
            $host = $app->make('config')->get('scout.meilisearch.host');
            $key  = $app->make('config')->get('scout.meilisearch.key');

            $client = new MeiliClient($host, $key);
            return new ExactMeilisearchEngine($client);
        });
    }
}
