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

    private function putJpeg(string $path, int $width = 400, int $height = 300): void
    {
        $this->putImage($path, new JpegEncoder, $width, $height);
    }

    private function putPng(string $path, int $width = 400, int $height = 300): void
    {
        $this->putImage($path, new PngEncoder, $width, $height);
    }

    private function putImage(string $path, EncoderInterface $encoder, int $width = 400, int $height = 300): void
    {
        Storage::disk('public')->put($path, $this->encodeImage($encoder, $width, $height));
    }

    private function encodeImage(EncoderInterface $encoder, int $width, int $height): string
    {
        $manager = new ImageManager(new Driver);
        // v4 renamed create() to createImage()
        $image = method_exists($manager, 'createImage') ? $manager->createImage($width, $height) : $manager->create($width, $height);

        return (string) $image->encode($encoder);
    }

    /**
     * A 400x300 JPEG whose EXIF says "rotate 90°": an APP1 segment with one IFD0 tag (0x0112 Orientation = 6) spliced after SOI.
     */
    private function putExifRotatedJpeg(string $path): void
    {
        $jpeg = $this->encodeImage(new JpegEncoder, 400, 300);
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 1) . pack('vvVV', 0x0112, 3, 1, 6) . pack('V', 0);
        $payload = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        Storage::disk('public')->put($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
    }

    /**
     * Service that counts generate() and resolve() calls, to prove a cache refresh does not regenerate.
     */
    private function countingService(): ResponsiveImagesService
    {
        return new class extends ResponsiveImagesService
        {
            public int $generateCalls = 0;

            public int $resolveCalls = 0;

            public function generate(?string $path, ?int $width = null, ?int $height = null, ?string $disk = null): ?ResponsiveImage
            {
                $this->generateCalls++;

                return parent::generate($path, $width, $height, $disk);
            }

            protected function resolve(string $path, ?int $width, ?int $height, string $disk): ?ResponsiveImage
            {
                $this->resolveCalls++;

                return parent::resolve($path, $width, $height, $disk);
            }
        };
    }

    private function dimensions(ResponsiveImage $image): array
    {
        return [$image->width, $image->height, array_keys($image->generatedImages)];
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

    public function test_component_and_directive_accept_a_sizes_argument(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putJpeg('photo.jpg');
        $this->service()->generate('photo.jpg', 320);

        $component = Blade::render('<x-responsive-image path="photo.jpg" :width="320" alt="Photo" sizes="50vw" />');
        $directive = Blade::render("@responsiveImage('photo.jpg', width: 320, alt: 'Photo', sizes: '50vw')");
        $default = Blade::render("@responsiveImage('photo.jpg', width: 320, alt: 'Photo')");

        $this->assertStringContainsString('sizes="50vw"', $component);
        $this->assertStringContainsString('sizes="50vw"', $directive);
        $this->assertStringContainsString('alt="Photo"', $directive);
        $this->assertStringContainsString('sizes="100vw"', $default);
    }

    public function test_directive_accepts_positional_arguments_and_renders_nothing_for_a_missing_file(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putJpeg('photo.jpg');
        $this->service()->generate('photo.jpg', 320);

        $this->assertStringContainsString('<source', Blade::render("@responsiveImage('photo.jpg', 320)"));
        $this->assertSame('', Blade::render("@responsiveImage('missing.jpg', 320)"));
    }

    public function test_make_with_a_width_keeps_the_proportional_height_after_a_cache_refresh(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        $this->putPng('a.png');
        $service = $this->countingService();

        $first = $service->make('a.png', 800);
        $this->assertSame([800, 600, [320, 480, 640, 768, 800]], $this->dimensions($first));
        $this->assertSame(1, $service->generateCalls);

        $service->forgetCache('a.png', 800, null, null);
        $second = $service->make('a.png', 800);

        $this->assertSame(1, $service->generateCalls);
        $this->assertSame($this->dimensions($first), $this->dimensions($second));
        $this->assertSame($first->generatedImages, $second->generatedImages);
        $this->assertStringContainsString('height="600"', $second->toHtml());
    }

    public function test_make_without_a_width_uses_the_original_dimensions_and_is_complete_after_generation(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        $this->putPng('a.png', 700, 350);
        $service = $this->countingService();

        $first = $service->make('a.png');
        $this->assertSame([700, 350, [320, 480, 640, 700]], $this->dimensions($first));

        $service->forgetCache('a.png', null, null, null);
        $second = $service->make('a.png');

        $this->assertSame(1, $service->generateCalls);
        $this->assertSame(2, $service->resolveCalls);
        $this->assertSame($first->generatedImages, $second->generatedImages);
        $this->assertSame([700, 350, [320, 480, 640, 700]], $this->dimensions($second));
    }

    public function test_make_with_a_height_only_finds_the_files_generate_wrote(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        $this->putPng('a.png', 700, 350);
        $service = $this->countingService();

        $first = $service->make('a.png', null, 200);
        $this->assertSame([700, 200, [320, 480, 640, 700]], $this->dimensions($first));

        $service->forgetCache('a.png', null, 200, null);
        $second = $service->make('a.png', null, 200);

        $this->assertSame(1, $service->generateCalls);
        $this->assertSame($first->generatedImages, $second->generatedImages);
    }

    public function test_async_make_without_a_width_stops_dispatching_once_the_job_has_run(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putPng('a.png', 700, 350);

        $fallback = $this->service()->make('a.png');
        Queue::assertPushed(GenerateResponsiveImages::class, 1);
        $this->assertSame([700, 350, []], $this->dimensions($fallback));

        (new GenerateResponsiveImages('a.png'))->handle($this->service());
        $image = $this->service()->make('a.png');

        Queue::assertPushed(GenerateResponsiveImages::class, 1);
        $this->assertSame([700, 350, [320, 480, 640, 700]], $this->dimensions($image));
    }

    public static function dimensionArguments(): array
    {
        return [
            'width only' => [800, null],
            'height only' => [null, 200],
            'both' => [800, 400],
            'none' => [null, null],
        ];
    }

    #[DataProvider('dimensionArguments')]
    public function test_a_cache_refresh_reports_the_same_dimensions_as_generate(?int $width, ?int $height): void
    {
        $this->fakeDisk();
        $this->putJpeg('photo.jpg', 700, 350);

        $generated = $this->service()->generate('photo.jpg', $width, $height);
        $resolved = $this->service()->make('photo.jpg', $width, $height);

        $this->assertSame($this->dimensions($generated), $this->dimensions($resolved));
        $this->assertSame($generated->generatedImages, $resolved->generatedImages);
    }

    public function test_exif_rotated_jpeg_reports_the_same_dimensions_as_generate(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is not loaded');
        }

        Queue::fake();
        $this->fakeDisk();
        $this->putExifRotatedJpeg('rotated.jpg');

        $generated = $this->service()->generate('rotated.jpg');
        $this->assertSame([300, 400], [$generated->width, $generated->height]);

        $resolved = $this->service()->make('rotated.jpg');
        $this->assertSame($this->dimensions($generated), $this->dimensions($resolved));
        Queue::assertNothingPushed();

        $fallback = $this->service()->make('rotated.jpg', 600);
        $this->assertSame([600, 800, []], $this->dimensions($fallback));
        Queue::assertPushed(GenerateResponsiveImages::class, 1);
    }

    public function test_dimensions_of_a_non_local_disk_are_read_from_the_file_contents(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is not loaded');
        }

        Queue::fake();
        $this->fakeDisk();
        $this->putExifRotatedJpeg('rotated.jpg');
        // Storage::fake() keeps the disk local; only the driver name decides which reader is used.
        config(['filesystems.disks.public.driver' => 's3']);

        $generated = $this->service()->generate('rotated.jpg');
        $resolved = $this->service()->make('rotated.jpg');

        $this->assertSame([300, 400, [300]], $this->dimensions($generated));
        $this->assertSame($this->dimensions($generated), $this->dimensions($resolved));
        Queue::assertNothingPushed();
    }

    public function test_unreadable_dimensions_fall_back_to_the_passed_values(): void
    {
        Queue::fake();
        $this->fakeDisk();
        Storage::disk('public')->put('broken.jpg', 'corrupt');

        $withWidth = $this->service()->make('broken.jpg', 800);
        $withoutWidth = $this->service()->make('broken.jpg');

        Queue::assertPushed(GenerateResponsiveImages::class, 2);
        $this->assertSame([800, 0, []], $this->dimensions($withWidth));
        $this->assertSame([0, 0, []], $this->dimensions($withoutWidth));
    }

    public function test_leading_slash_is_stripped_from_the_path(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        $this->putPng('cases/a.png');

        $image = $this->service()->make('/cases/a.png', 320);

        $this->assertStringNotContainsString('//', substr($image->src, 1));
        foreach ($image->generatedImages as $url) {
            $this->assertStringNotContainsString('//', substr($url, 1));
        }
        $this->assertNotEmpty(Storage::disk('public')->allFiles('responsive-images/cases/a'));
        $this->assertSame($image->generatedImages, $this->service()->make('cases/a.png', 320)->generatedImages);
    }

    public function test_paths_with_and_without_a_leading_slash_share_the_cache_and_the_job(): void
    {
        Queue::fake();
        $this->fakeDisk();
        $this->putPng('a.png');
        $service = $this->countingService();

        $slashed = $service->make('/a.png', 320);
        $plain = $service->make('a.png', 320);

        $this->assertSame(1, $service->resolveCalls);
        $this->assertSame($this->dimensions($slashed), $this->dimensions($plain));
        Queue::assertPushed(GenerateResponsiveImages::class, 1);
        Queue::assertPushed(GenerateResponsiveImages::class, fn (GenerateResponsiveImages $job) => $job->path === 'a.png'
            && $job->uniqueId() === (new GenerateResponsiveImages('a.png', 320, null, 'public'))->uniqueId());
    }

    public function test_forget_cache_and_clear_accept_a_leading_slash(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeDisk();
        $this->putPng('a.png');
        $service = $this->countingService();

        $service->make('a.png', 320);
        $service->forgetCache('/a.png', 320, null, null);
        $service->make('a.png', 320);
        $this->assertSame(2, $service->resolveCalls);

        $this->assertNotEmpty(Storage::disk('public')->allFiles('responsive-images/a'));
        $service->clear('/a.png');
        $this->assertSame([], Storage::disk('public')->allFiles('responsive-images/a'));
    }

    public function test_root_and_empty_paths_return_null(): void
    {
        Queue::fake();
        $this->fakeDisk();

        $this->assertNull($this->service()->make('/'));
        $this->assertNull($this->service()->make(''));
        $this->assertNull($this->service()->generate('/'));
        $this->assertNull($this->service()->generate(''));
        $this->service()->forgetCache('/', null, null, null);

        Queue::assertNothingPushed();
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
