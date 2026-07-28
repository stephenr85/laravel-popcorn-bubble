<?php

namespace Rushing\Popcorn\Bubble\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Bubble\BubbleServiceProvider;
use Rushing\Popcorn\PopcornServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, BubbleServiceProvider::class];
    }
}
