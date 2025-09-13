<?php

namespace LadyByron\ExactSearch;

use Illuminate\Support\ServiceProvider;

class ExactScoutServiceProvider extends ServiceProvider
{
    public function register()
    {
        // 如果 Scout 或 Meili SDK 不在，直接跳过，避免后台 500
        if (!class_exists(\Laravel\Scout\EngineManager::class) || !class_exists(\Meilisearch\Client::class)) {
            return;
        }

        $app = $this->app;

        // 当 EngineManager 被解析时再覆盖 meilisearch 引擎（确保加载顺序安全）
        $app->resolving(\Laravel\Scout\EngineManager::class, function (\Laravel\Scout\EngineManager $manager) use ($app) {
            $manager->extend('meilisearch', function () use ($app) {
                // 1) 优先读容器环境变量（推荐用 docker-compose 注入）
                $host = getenv('SCOUT_MEILISEARCH_HOST') ?: getenv('MEILISEARCH_HOST');
                $key  = getenv('SCOUT_MEILISEARCH_KEY')  ?: getenv('MEILISEARCH_KEY');

                // 2) 回落到 Scout 扩展在后台保存的配置（若存在）
                if (!$host && class_exists(\Flarum\Settings\SettingsRepositoryInterface::class)) {
                    /** @var \Flarum\Settings\SettingsRepositoryInterface $settings */
                    $settings = $app->make(\Flarum\Settings\SettingsRepositoryInterface::class);
                    $host = $settings->get('clarkwinkelmann-scout.meilisearchHost')
                        ?: $settings->get('clarkwinkelmann-scout.meilisearchUrl')
                        ?: 'http://meilisearch:7700';
                    $key = $key ?: $settings->get('clarkwinkelmann-scout.meilisearchKey');
                }

                $client = new \Meilisearch\Client($host ?: 'http://meilisearch:7700', $key ?: null);

                // 用我们自定义的引擎，强制 matchingStrategy=all，并对纯中文做短语搜索
                return new \LadyByron\ExactSearch\ExactMeilisearchEngine($client);
            });

            return $manager;
        });
    }
}
