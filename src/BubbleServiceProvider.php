<?php

namespace Rushing\Popcorn\Bubble;

use Illuminate\Support\ServiceProvider;

class BubbleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/popcorn-bubble.php', 'popcorn-bubble');

        // The two reference providers ship in-package; a host or third party registers more. Neither
        // the provider list nor the config is passed in: the runner reads both through, so describing
        // it below cannot freeze either at boot (registry-kernel 38, archetype c).
        $this->app->singleton(BwrapRunner::class, fn () => new BwrapRunner);
    }

    public function boot(): void
    {

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/popcorn-bubble.php' => $this->app->configPath('popcorn-bubble.php'),
            ], 'popcorn-bubble-config');
        }
    }
}
