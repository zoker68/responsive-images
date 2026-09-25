<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests\Unit;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Zoker\ResponsiveImages\Jobs\GenerateResponsiveImages;
use Zoker\ResponsiveImages\ResponsiveImagesService;
use Zoker\ResponsiveImages\Tests\TestCase;

#[RequiresPhpExtension('imagick')]
class ImagickDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'responsive-images.driver' => 'imagick',
            'responsive-images.disk' => 'public',
            'responsive-images.output_disk' => 'public',
        ]);
    }

    private function service(): ResponsiveImagesService
    {
        return app('responsive-images');
    }

    private function putImage(string $path, string $format, int $frames = 1): void
    {
        $imagick = new Imagick;

        foreach (range(1, $frames) as $i) {
            $imagick->newImage(400, 300, new ImagickPixel($i % 2 ? 'red' : 'blue'));
            $imagick->setImageFormat($format);
            $imagick->setImageDelay(10);
        }

        Storage::disk('public')->put($path, $imagick->getImagesBlob());
    }

    public function test_animated_gif_stays_animated(): void
    {
        $this->putImage('anim.gif', 'gif', frames: 3);

        $image = $this->service()->generate('anim.gif', 320);

        $webp = new Imagick;
        $webp->readImageBlob(Storage::disk('public')->get(
            str_replace(Storage::disk('public')->url(''), '', $image->getImage(320))
        ));

        $this->assertSame('webp', $image->format);
        $this->assertSame(3, $webp->getNumberImages());
    }

    #[TestWith(['scan.tiff'])]
    #[TestWith(['scan.tif'])]
    public function test_tiff_is_converted_to_webp(string $path): void
    {
        $this->putImage($path, 'tiff');

        $this->assertNotNull($this->service()->generate($path, 320));
        $html = $this->service()->make($path, 320)->toHtml();

        $this->assertStringContainsString('type="image/webp"', $html);
    }

    public function test_tiff_before_generation_falls_back_to_a_synchronous_webp_original(): void
    {
        Queue::fake();
        $this->putImage('scan.tiff', 'tiff');

        $image = $this->service()->make('scan.tiff', 320);

        Queue::assertPushed(GenerateResponsiveImages::class);
        $this->assertStringEndsWith('.webp', $image->src);
        $this->assertSame('webp', $image->format);
        $this->assertFalse($image->hasSource());
        $this->assertCount(1, Storage::disk('public')->allFiles('responsive-images'));
    }

    public function test_jpeg_before_generation_still_falls_back_to_the_untouched_original(): void
    {
        Queue::fake();
        $this->putImage('photo.jpg', 'jpeg');

        $image = $this->service()->make('photo.jpg', 320);

        $this->assertSame(Storage::disk('public')->url('photo.jpg'), $image->src);
        $this->assertSame([], Storage::disk('public')->allFiles('responsive-images'));
    }

    public function test_extension_missing_from_the_imagemagick_build_is_served_as_is(): void
    {
        Queue::fake();
        config(['responsive-images.extensions' => ['jpg', 'nosuchformat']]);
        Storage::disk('public')->put('file.nosuchformat', 'x');

        $image = $this->service()->make('file.nosuchformat', 320);

        Queue::assertNothingPushed();
        $this->assertSame(Storage::disk('public')->url('file.nosuchformat'), $image->src);
    }
}
