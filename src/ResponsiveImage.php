<?php

namespace Zoker\ResponsiveImages;

class ResponsiveImage
{
    public string $srcset;

    public function __construct(
        public string $src,
        public array $generatedImages,
        public string $sizes,
        public int $width,
        public int $height,
        public string $format
    ) {
        $this->srcset = $this->buildSrcset();
    }

    public function getImages(): array
    {
        return $this->generatedImages;
    }

    public function getImage(int $width): string
    {
        if ($this->generatedImages === []) {
            return $this->src;
        }

        $closest = null;

        foreach ($this->generatedImages as $size => $url) {
            if ($size >= $width) {
                if ($closest === null || $closest < $width || $size < $closest) {
                    $closest = $size;
                }
            } elseif ($closest === null || ($closest < $width && $size > $closest)) {
                $closest = $size;
            }
        }

        return $this->generatedImages[$closest];
    }

    public function getSrcset(): string
    {
        return $this->srcset;
    }

    protected function buildSrcset(): string
    {
        $parts = [];

        foreach ($this->generatedImages as $w => $url) {
            if ($w > 0) {
                $parts[] = "{$url} {$w}w";
            }
        }

        return implode(', ', $parts);
    }

    public function hasSource(): bool
    {
        return $this->format === config('responsive-images.format') && $this->srcset !== '';
    }

    public function toHtml(string $alt = '', string $loading = 'lazy', array $attributes = [], ?string $sizes = null): string
    {
        $imgAttributes = array_merge([
            'src' => $this->src,
            'loading' => $loading,
            'decoding' => 'async',
            'alt' => $alt,
        ], $attributes);

        if ($this->width > 0) {
            $imgAttributes['width'] = $this->width;
        }

        if ($this->height > 0) {
            $imgAttributes['height'] = $this->height;
        }

        $imgAttrsString = $this->buildAttributesString($imgAttributes);

        /** @var view-string $viewName */
        $viewName = 'responsive-images::picture';

        return view($viewName, [
            'hasSource' => $this->hasSource(),
            'format' => $this->format,
            'srcset' => $this->srcset,
            'sizes' => $sizes ?? $this->sizes,
            'imgAttrsString' => $imgAttrsString,
        ])->render();
    }

    protected function buildAttributesString(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $parts[] = $key;
                }
            } else {
                $parts[] = sprintf('%s="%s"', $key, htmlspecialchars($value, ENT_QUOTES));
            }
        }

        return implode(' ', $parts);
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }
}
