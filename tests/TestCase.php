<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zoker\ResponsiveImages\ResponsiveImagesServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ResponsiveImagesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Use the array cache store so the flexible() cache used by make() works in tests.
        $app['config']->set('responsive-images.cache_store', 'array');
    }
}
