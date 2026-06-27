<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests\Unit;

use Zoker\ResponsiveImages\ResponsiveImage;
use Zoker\ResponsiveImages\Tests\TestCase;

class ResponsiveImageTest extends TestCase
{
    private function makeImage(): ResponsiveImage
    {
        return new ResponsiveImage(
            src: 'https://cdn.test/img-1024.webp',
            generatedImages: [
                320 => 'https://cdn.test/img-320.webp',
                640 => 'https://cdn.test/img-640.webp',
                1024 => 'https://cdn.test/img-1024.webp',
            ],
            sizes: '100vw',
            width: 1024,
            height: 768,
            format: 'webp',
        );
    }

    public function test_get_images_returns_the_generated_map(): void
    {
        $this->assertCount(3, $this->makeImage()->getImages());
    }

    public function test_get_image_picks_the_smallest_size_at_or_above_the_width(): void
    {
        $image = $this->makeImage();

        $this->assertEquals('https://cdn.test/img-320.webp', $image->getImage(100));
        $this->assertEquals('https://cdn.test/img-320.webp', $image->getImage(320));
        $this->assertEquals('https://cdn.test/img-640.webp', $image->getImage(500));
    }

    public function test_get_image_falls_back_to_the_largest_when_width_exceeds_all(): void
    {
        $this->assertEquals('https://cdn.test/img-1024.webp', $this->makeImage()->getImage(2000));
    }

    public function test_get_srcset_lists_each_size(): void
    {
        $srcset = $this->makeImage()->getSrcset();

        $this->assertStringContainsString('https://cdn.test/img-320.webp 320w', $srcset);
        $this->assertStringContainsString('https://cdn.test/img-1024.webp 1024w', $srcset);
        $this->assertEquals(3, substr_count($srcset, 'w,') + 1);
    }

    public function test_to_html_renders_the_picture_view_with_attributes(): void
    {
        $html = $this->makeImage()->toHtml('Alt text', 'eager', ['class' => 'rounded']);

        $this->assertStringContainsString('https://cdn.test/img-1024.webp', $html);
        $this->assertStringContainsString('alt="Alt text"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('class="rounded"', $html);
        $this->assertStringContainsString('width="1024"', $html);
        $this->assertStringContainsString('height="768"', $html);
    }

    public function test_to_html_omits_zero_dimensions(): void
    {
        $image = new ResponsiveImage('s.webp', [0 => 's.webp'], '100vw', 0, 0, 'webp');

        $html = $image->toHtml();

        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringNotContainsString('height=', $html);
    }
}
