<?php

namespace Zoker\ResponsiveImages;

class ResponsiveImage
{
    public function __construct(
        public string $src,
        public array $generatedImages,
        public string $sizes,
        public int $width,
        public int $height,
        public string $format
    ) {}

    public function getImages(): array
    {
        return $this->generatedImages;
    }

    public function getImage(int $width): string
    {
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
        $parts = [];

        foreach ($this->generatedImages as $w => $url) {
            $parts[] = "{$url} {$w}w";
        }

        return implode(', ', $parts);
    }

    public function toHtml(string $alt = '', string $loading = 'lazy', array $attributes = []): string
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
            'format' => $this->format,
            'srcset' => $this->getSrcset(),
            'sizes' => $this->sizes,
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
