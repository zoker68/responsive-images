<?php

namespace Zoker\ResponsiveImages;

class ResponsiveImage
{
    public function __construct(
        public string $src,
        public string $srcset,
        public string $sizes,
        public int $width,
        public int $height,
        public string $format
    ) {}

    public function toHtml(string $alt = '', string $loading = 'lazy', array $attributes = []): string
    {
        $imgAttributes = array_merge([
            'src' => $this->src,
            'width' => $this->width,
            'height' => $this->height,
            'loading' => $loading,
            'decoding' => 'async',
            'alt' => $alt,
        ], $attributes);

        $imgAttrsString = $this->buildAttributesString($imgAttributes);

        /** @var view-string $viewName */
        $viewName = 'responsive-images::picture';

        return view($viewName, [
            'format' => $this->format,
            'srcset' => $this->srcset,
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
