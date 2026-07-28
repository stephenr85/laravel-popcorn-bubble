<?php

namespace Rushing\Popcorn\Bubble;

use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\Bubble\Providers\NodeProvider;
use Rushing\Popcorn\Bubble\Providers\PythonProvider;

class BubbleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/popcorn-bubble.php', 'popcorn-bubble');

        // The two reference providers ship in-package; a host or third party registers more.
        $this->app->singleton(BwrapRunner::class, function ($app) {
            return new BwrapRunner(
                providers: [new NodeProvider, new PythonProvider],
                config: (array) $app['config']->get('popcorn-bubble', []),
            );
        });
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
