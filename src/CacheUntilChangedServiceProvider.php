<?php

namespace Cyberwizard\CacheUntilChanged;

use Illuminate\Support\ServiceProvider;

class CacheUntilChangedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cache-until-changed.php', 'cache-until-changed');

        $this->app->singleton(CacheUntilChanged::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cache-until-changed.php' => config_path('cache-until-changed.php'),
            ], 'cache-until-changed-config');
        }
    }
}
