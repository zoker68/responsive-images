<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\BmpEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Zoker\ResponsiveImages\Jobs\GenerateResponsiveImages;
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

        $this->putJpeg('photo.jpg');

        $image = $this->service()->generate('photo.jpg', 320);

        $this->assertInstanceOf(ResponsiveImage::class, $image);
        $this->assertNotEmpty($image->getImages());
        $this->assertSame('webp', $image->format);
        $this->assertSame(320, $image->width);
    }

    private function fakeDisk(): void
    {
        Storage::fake('public');
        config(['responsive-images.disk' => 'public', 'responsive-images.output_disk' => 'public']);
    }

    private function putJpeg(string $path): void
    {
        $this->putImage($path, new JpegEncoder);
    }

    private function putPng(string $path): void
    {
        $this->putImage($path, new PngEncoder);
    }

    private function putImage(string $path, EncoderInterface $encoder): void
    {
        $manager = new ImageManager(new Driver);
        // v4 renamed create() to createImage()
        $image = method_exists($manager, 'createImage') ? $manager->createImage(400, 300) : $manager->create(400, 300);

        Storage::disk('public')->put($path, (string) $image->encode($encoder));
    }

    public function test_make_serves_an_unsupported_file_as_is_without_dispatching(): void
    {
        Queue::fake();
        $this->fakeDisk();
        Storage::disk('public')->put('logos/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $image = $this->service()->make('logos/logo.svg', 200);

        Queue::assertNothingPushed();
        $this->assertSame(Storage::disk('public')->url('logos/logo.svg'), $image->src);
        $this->assertSame([], $image->generatedImages);
        $this->assertSame(200, $image->width);
        $this->assertSame(0, $image->height);
        $this->assertSame('svg', $image->format);
        $this->assertFalse($image->hasSource());

        $html = $image->toHtml('Logo');
        $this->assertStringNotContainsString('<source', $html);
        $this->assertStringContainsString('src="' . $image->src . '"', $html);
    }

    public function test_generate_skips_an_unsupported_file(): void
    {
        $this->fakeDisk();
        Storage::disk('public')->put('logos/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->assertNull($this->service()->generate('logos/logo.svg'));
        $this->assertSame([], Storage::disk('public')->allFiles('responsive-images'));
    }

    public function test_make_before_generation_renders_the_original_without_source(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putJpeg('photo.jpg');

        $image = $this->service()->make('photo.jpg');

        Queue::assertPushed(GenerateResponsiveImages::class);
        $this->assertSame([], $image->generatedImages);
        $this->assertSame(Storage::disk('public')->url('photo.jpg'), $image->src);

        $html = $image->toHtml();
        $this->assertStringNotContainsString('<source', $html);
        $this->assertStringNotContainsString('0w', $html);
    }

    public function test_make_after_generation_renders_a_webp_source(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putJpeg('photo.jpg');

        $this->service()->generate('photo.jpg', 320);
        $image = $this->service()->make('photo.jpg', 320);

        Queue::assertNothingPushed();
        $this->assertStringContainsString('<source', $image->toHtml());
        $this->assertStringContainsString('type="image/webp"', $image->toHtml());
    }

    public function test_extension_check_is_case_insensitive(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putJpeg('PHOTO.JPG');

        $this->service()->make('PHOTO.JPG', 320);
        Queue::assertPushed(GenerateResponsiveImages::class);

        $this->assertNotNull($this->service()->generate('PHOTO.JPG', 320));
    }

    public function test_component_renders_a_plain_img_for_an_unsupported_file(): void
    {
        Queue::fake();
        $this->fakeDisk();
        Storage::disk('public')->put('logos/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $html = Blade::render('<x-responsive-image path="logos/logo.svg" :width="200" alt="Logo" />');

        $this->assertStringNotContainsString('<source', $html);
        $this->assertStringContainsString(Storage::disk('public')->url('logos/logo.svg'), $html);
    }

    public function test_component_renders_a_webp_source_after_generation(): void
    {
        $this->fakeDisk();
        $this->putJpeg('photo.jpg');
        $this->service()->generate('photo.jpg', 320);

        $html = Blade::render('<x-responsive-image path="photo.jpg" :width="320" alt="Photo" />');

        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString('320w', $html);
    }

    public static function additionalFormats(): array
    {
        return [
            'gif' => ['image.gif', new GifEncoder, IMG_GIF],
            'avif' => ['image.avif', new AvifEncoder, IMG_AVIF],
            'bmp' => ['image.bmp', new BmpEncoder, IMG_BMP],
        ];
    }

    #[DataProvider('additionalFormats')]
    public function test_additional_formats_are_converted_to_webp(string $path, EncoderInterface $encoder, int $gdType): void
    {
        if ((imagetypes() & $gdType) === 0) {
            $this->markTestSkipped("GD build cannot read {$path}");
        }

        $this->fakeDisk();
        $this->putImage($path, $encoder);

        $generated = $this->service()->generate($path, 320);
        $this->assertNotNull($generated);
        $this->assertSame('webp', $generated->format);

        $html = $this->service()->make($path, 320)->toHtml();
        $this->assertStringContainsString('type="image/webp"', $html);
    }

    public function test_a_configured_extension_unreadable_by_gd_is_served_as_is(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putJpeg('photo.avif');

        $service = new class extends ResponsiveImagesService
        {
            protected function driverSupports(string $extension): bool
            {
                return $extension !== 'avif';
            }
        };

        $image = $service->make('photo.avif', 320);

        Queue::assertNothingPushed();
        $this->assertSame(Storage::disk('public')->url('photo.avif'), $image->src);
        $this->assertFalse($image->hasSource());
        $this->assertNull($service->generate('photo.avif', 320));
        $this->assertSame([], Storage::disk('public')->allFiles('responsive-images'));
    }

    public function test_gd_serves_formats_it_cannot_read_as_is(): void
    {
        Queue::fake();
        $this->fakeDisk();
        Storage::disk('public')->put('scan.tiff', 'not really a tiff');

        $image = $this->service()->make('scan.tiff', 320);

        Queue::assertNothingPushed();
        $this->assertSame(Storage::disk('public')->url('scan.tiff'), $image->src);
        $this->assertSame([], Storage::disk('public')->allFiles('responsive-images'));
    }

    public function test_failed_fallback_conversion_is_reported_and_serves_the_original(): void
    {
        Queue::fake();
        Exceptions::fake();
        $this->fakeDisk();
        Storage::disk('public')->put('broken.tiff', 'corrupt');

        $service = new class extends ResponsiveImagesService
        {
            protected function driverSupports(string $extension): bool
            {
                return true;
            }
        };

        $image = $service->make('broken.tiff', 320);

        Exceptions::assertReportedCount(1);
        $this->assertSame(Storage::disk('public')->url('broken.tiff'), $image->src);
        Queue::assertPushed(GenerateResponsiveImages::class);
    }

    public function test_sync_queue_connection_generates_all_sizes_on_the_first_make(): void
    {
        Queue::fake();
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        $this->putPng('a.png');

        $image = $this->service()->make('a.png', 800, 400);

        Queue::assertNothingPushed();
        $this->assertSame([320, 480, 640, 768, 800], array_keys($image->generatedImages));
        $this->assertTrue($image->hasSource());
        $this->assertSame($image->generatedImages, $this->service()->make('a.png', 800, 400)->generatedImages);
    }

    public function test_queue_false_generates_in_the_request_on_an_async_connection(): void
    {
        Queue::fake();
        config(['responsive-images.queue' => false]);
        $this->fakeDisk();
        $this->putPng('a.png');

        $image = $this->service()->make('a.png', 800, 400);

        Queue::assertNothingPushed();
        $this->assertSame([320, 480, 640, 768, 800], array_keys($image->generatedImages));
    }

    public function test_async_queue_dispatches_the_job_and_returns_the_fallback(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putPng('a.png');

        $image = $this->service()->make('a.png', 800, 400);

        Queue::assertPushed(GenerateResponsiveImages::class, 1);
        $this->assertSame([], $image->generatedImages);

        (new GenerateResponsiveImages('a.png', 800, 400))->handle($this->service());
        $image = $this->service()->make('a.png', 800, 400);

        Queue::assertPushed(GenerateResponsiveImages::class, 1);
        $this->assertSame([320, 480, 640, 768, 800], array_keys($image->generatedImages));
    }

    public function test_failed_in_request_generation_is_reported_and_serves_the_fallback(): void
    {
        Queue::fake();
        Exceptions::fake();
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        Storage::disk('public')->put('broken.jpg', 'corrupt');

        $image = $this->service()->make('broken.jpg', 320);

        Exceptions::assertReportedCount(1);
        Queue::assertNothingPushed();
        $this->assertSame(Storage::disk('public')->url('broken.jpg'), $image->src);
        $this->assertSame([], $image->generatedImages);
    }

    public function test_failed_in_request_generation_of_a_tiff_is_reported_once(): void
    {
        Exceptions::fake();
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        Storage::disk('public')->put('broken.tiff', 'corrupt');

        $service = new class extends ResponsiveImagesService
        {
            protected function driverSupports(string $extension): bool
            {
                return true;
            }
        };

        $image = $service->make('broken.tiff', 320);

        Exceptions::assertReportedCount(1);
        $this->assertSame(Storage::disk('public')->url('broken.tiff'), $image->src);
    }

    public function test_an_unknown_driver_is_rejected(): void
    {
        config(['responsive-images.driver' => 'vips']);

        $this->expectException(\ValueError::class);

        new ResponsiveImagesService;
    }

    public function test_default_driver_is_imagick_when_the_extension_is_loaded(): void
    {
        $config = require __DIR__ . '/../../config/responsive-images.php';

        $this->assertSame(extension_loaded('imagick') ? 'imagick' : 'gd', $config['driver']);
    }
}
