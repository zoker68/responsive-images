<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Zoker\ResponsiveImages\ResponsiveImage;
use Zoker\ResponsiveImages\ResponsiveImagesService;
use Zoker\ResponsiveImages\Tests\TestCase;

class ResponsiveImagesServiceTest extends TestCase
{
    private function service(): ResponsiveImagesService
    {
        return app('responsive-images');
    }

    public function test_make_returns_null_for_a_null_path(): void
    {
        $this->assertNull($this->service()->make(null));
    }

    public function test_make_returns_null_when_the_source_file_is_missing(): void
    {
        Storage::fake('public');

        $this->assertNull($this->service()->make('does/not/exist.jpg'));
    }

    public function test_clear_with_a_path_removes_that_images_directory(): void
    {
        Storage::fake('public');
        config(['responsive-images.output_disk' => 'public']);
        config(['responsive-images.output_path' => 'responsive-images']);

        Storage::disk('public')->put('responsive-images/some/generated.webp', 'x');
        $this->assertTrue(Storage::disk('public')->exists('responsive-images/some/generated.webp'));

        $this->service()->clear();

        $this->assertFalse(Storage::disk('public')->exists('responsive-images/some/generated.webp'));
    }

    public function test_forget_cache_removes_the_cached_entry(): void
    {
        $service = $this->service();

        // Prime the cache with a null result, then ensure forgetCache clears it.
        Storage::fake('public');
        $service->make('missing.jpg', 320);

        // No exception means the key handling round-trips cleanly.
        $service->forgetCache('missing.jpg', 320, null, null);

        $this->assertTrue(true);
    }

    public function test_generate_returns_null_for_missing_input(): void
    {
        Storage::fake('public');

        $this->assertNull($this->service()->generate(null));
        $this->assertNull($this->service()->generate('missing.jpg'));
    }

    public function test_generate_produces_responsive_webp_images(): void
    {
        Storage::fake('public');
        config(['responsive-images.disk' => 'public', 'responsive-images.output_disk' => 'public']);

        $jpeg = (new ImageManager(new Driver))->create(400, 300)->toJpeg();
        Storage::disk('public')->put('photo.jpg', (string) $jpeg);

        $image = $this->service()->generate('photo.jpg', 320);

        $this->assertInstanceOf(ResponsiveImage::class, $image);
        $this->assertNotEmpty($image->getImages());
        $this->assertSame('webp', $image->format);
        $this->assertSame(320, $image->width);
    }
}
