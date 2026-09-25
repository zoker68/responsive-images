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
        // The default depends on ext-imagick; pin it so results do not differ between host and Sail.
        $app['config']->set('responsive-images.driver', 'gd');
        // phpunit.xml sets the sync connection, which makes make() generate in the request.
        $app['config']->set('queue.default', 'database');
    }
}
