<?php

use Flarum\Extend;
use Illuminate\Contracts\Container\Container;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeiliClient;
use LadyByron\ExactSearch\ExactMeilisearchEngine;

return [
    (new Extend\ServiceProvider)->register(function (Container $app) {
        $app->resolving(EngineManager::class, function (EngineManager $manager) {
            $manager->extend('meilisearch', function () {
                // 1) 优先环境变量（推荐在 PHP 容器里设置）
                $host = getenv('SCOUT_MEILISEARCH_HOST') ?: getenv('MEILISEARCH_HOST');
                $key  = getenv('SCOUT_MEILISEARCH_KEY')  ?: getenv('MEILISEARCH_KEY');

                // 2) 可选回落：若你的环境有 Config/Settings，可自行添加读取逻辑
                if (!$host) $host = 'http://127.0.0.1:7700';

                $client = new MeiliClient($host, $key ?: null);
                return new ExactMeilisearchEngine($client);
            });
        });
    }),
];
