<?php

declare(strict_types=1);

namespace Parvion\LaravelLogPruner;

use Illuminate\Support\ServiceProvider;
use Parvion\LaravelLogPruner\Console\Commands\RotateLogsCommand;

/**
 * LogPrunerServiceProvider
 *
 * Bootstraps the parvion/laravel-log-pruner package into the host Laravel
 * application. Responsible for:
 *
 *  - Merging the package config with the host application's config system.
 *  - Publishing the config file to the host application via `vendor:publish`.
 *  - Registering the RotateLogsCommand Artisan command in CLI context.
 *
 * Auto-discovery is handled via the `extra.laravel.providers` key in
 * composer.json, so no manual registration is needed in the host app.
 *
 * After installation, publish the config with:
 *   php artisan vendor:publish --tag=log-pruner-config
 *
 * @package Parvion\LaravelLogPruner
 * @author  Anand Kumar <anandkumar101002@gmail.com>
 * @license MIT
 */
class LogPrunerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * mergeConfigFrom() loads our config/log-pruner.php as the package default.
     * If the host application has published their own copy, that copy takes
     * precedence for any keys they have explicitly set — unset keys fall back
     * to our package defaults automatically (deep merge behaviour).
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/log-pruner.php',
            'log-pruner'
        );
    }

    /**
     * Bootstrap any application services.
     *
     * 1. Publishes the config file so users can customise it per-project.
     * 2. Registers the Artisan command only in CLI context to avoid overhead
     *    on HTTP request cycles.
     */
    public function boot(): void
    {
        // ── Publish the config file ────────────────────────────────────────
        // Run `php artisan vendor:publish --tag=log-pruner-config` in the
        // host app to copy the config to config/log-pruner.php.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/log-pruner.php' => config_path('log-pruner.php'),
            ], 'log-pruner-config');

            // ── Register the Artisan command ───────────────────────────────
            $this->commands([
                RotateLogsCommand::class,
            ]);
        }
    }
}
