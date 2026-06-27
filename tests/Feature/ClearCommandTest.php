<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests\Feature;

use Zoker\ResponsiveImages\ResponsiveImagesService;
use Zoker\ResponsiveImages\Tests\TestCase;

class ClearCommandTest extends TestCase
{
    public function test_it_clears_all_images_without_a_path(): void
    {
        $this->mock(ResponsiveImagesService::class, function ($mock) {
            $mock->shouldReceive('clear')->once()->with(null);
        });

        $this->artisan('responsive-images:clear')
            ->expectsOutputToContain('Cleared all responsive images')
            ->assertSuccessful();
    }

    public function test_it_clears_a_single_path(): void
    {
        $this->mock(ResponsiveImagesService::class, function ($mock) {
            $mock->shouldReceive('clear')->once()->with('photos/a.jpg');
        });

        $this->artisan('responsive-images:clear', ['path' => 'photos/a.jpg'])
            ->expectsOutputToContain('Cleared responsive images for: photos/a.jpg')
            ->assertSuccessful();
    }
}
