<?php

namespace LadyByron\ExactSearch;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;

class ExactScoutServiceProvider extends AbstractServiceProvider
{
    public function boot(EngineManager $engines)
    {
        // 没装 meilisearch-php 时直接跳过，避免后台 500
        if (!class_exists(Client::class)) {
            return;
        }

        // 覆盖同名 'meilisearch' 驱动（无需在后台切换驱动选择）
        $engines->extend('meilisearch', function ($app) {
            // 1) 环境变量（推荐用 docker-compose 注入到 PHP 容器）
            $host = getenv('SCOUT_MEILISEARCH_HOST') ?: getenv('MEILISEARCH_HOST') ?: null;
            $key  = getenv('SCOUT_MEILISEARCH_KEY')  ?: getenv('MEILISEARCH_KEY')  ?: null;

            // 2) Scout 扩展后台设置（若存在）
            if (!$host && interface_exists(SettingsRepositoryInterface::class)) {
                /** @var SettingsRepositoryInterface $settings */
                $settings = $app->make(SettingsRepositoryInterface::class);
                $host = $settings->get('clarkwinkelmann-scout.meilisearchHost')
                    ?: $settings->get('clarkwinkelmann-scout.meilisearchUrl')
                    ?: $host;
                $key = $key ?: $settings->get('clarkwinkelmann-scout.meilisearchKey');
            }

            // 3) Laravel 配置（若存在）
            if (!$host && isset($app['config'])) {
                $cfg  = $app['config'];
                $host = $cfg->get('scout.meilisearch.host', $host);
                $key  = $cfg->get('scout.meilisearch.key',  $key);
            }

            // 4) 兜底：容器内网直连
            $host = $host ?: 'http://meilisearch:7700';

            return new ExactMeilisearchEngine(new Client($host, $key ?: null));
        });
    }
}

