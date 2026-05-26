<?php

namespace Zoker\ResponsiveImages;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ResponsiveImagesService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    public function make(
        string $path,
        ?int $width = null,
        ?int $height = null,
        ?string $disk = null
    ): ?ResponsiveImage {
        $disk = $disk ?? config('responsive-images.disk');

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        // v4 uses read(), v3 uses make()
        // @phpstan-ignore-next-line (supports both v3 and v4)
        if (method_exists($this->manager, 'read')) {
            // v4: read from binary data
            $originalImage = $this->manager->read(
                Storage::disk($disk)->get($path)
            );
        } else {
            // v3: make from file path
            // @phpstan-ignore-next-line (make() exists in v3)
            $originalImage = $this->manager->make(
                Storage::disk($disk)->path($path)
            );
        }

        $originalWidth = $originalImage->width();
        $originalHeight = $originalImage->height();

        if ($width === null) {
            $width = $originalWidth;
        }

        if ($height === null && $width !== $originalWidth) {
            $aspectRatio = $originalHeight / $originalWidth;
            $height = (int) round($width * $aspectRatio);
        } elseif ($height === null) {
            $height = $originalHeight;
        }

        $sizes = $this->calculateSizes($width);
        $outputDisk = config('responsive-images.output_disk');
        $outputPath = config('responsive-images.output_path');
        $quality = config('responsive-images.quality');
        $format = config('responsive-images.format');

        $lastModified = Storage::disk($disk)->lastModified($path);

        $pathInfo = pathinfo($path);
        $originalFilename = $pathInfo['filename'];
        $imageDirectory = $this->getImageDirectory($path);
        $fullOutputPath = "{$outputPath}/{$imageDirectory}";

        $generatedImages = [];
        $aspectRatio = $originalHeight / $originalWidth;

        foreach ($sizes as $size) {
            $resizedHeight = $height
                ? (int) round($size * ($height / $width))
                : (int) round($size * $aspectRatio);

            $imageHash = md5(implode('|', [
                $lastModified,
                $size,
                $resizedHeight,
                $quality,
                $format,
            ]));

            $outputFileName = "{$originalFilename}-{$size}-{$imageHash}.{$format}";
            $outputFilePath = "{$fullOutputPath}/{$outputFileName}";

            if (! Storage::disk($outputDisk)->exists($outputFilePath)) {
                $resizedImage = clone $originalImage;

                // v3 vs v4 methods
                // @phpstan-ignore-next-line (supports both v3 and v4)
                if (method_exists($resizedImage, 'cover')) {
                    // v4
                    if ($height && $width) {
                        $resizedImage->cover($size, $resizedHeight);
                    } else {
                        $resizedImage->scale(width: $size);
                    }
                    $encoded = $resizedImage->toWebp($quality);
                } else {
                    // v3
                    // @phpstan-ignore-next-line (v3 methods)
                    if ($height && $width) {
                        $resizedImage->fit($size, $resizedHeight);
                    } else {
                        $resizedImage->resize($size, null, function ($constraint) {
                            $constraint->aspectRatio();
                        });
                    }
                    $encoded = $resizedImage->encode('webp', $quality);
                }

                Storage::disk($outputDisk)->put(
                    $outputFilePath,
                    (string) $encoded
                );
            }

            $generatedImages[$size] = Storage::disk($outputDisk)->url($outputFilePath);
        }

        return new ResponsiveImage(
            src: end($generatedImages),
            srcset: $this->buildSrcset($generatedImages),
            sizes: '100vw',
            width: $width,
            height: $height,
            format: $format
        );
    }

    protected function calculateSizes(int $targetWidth): array
    {
        $breakpoints = config('responsive-images.breakpoints', []);

        $sizes = array_filter($breakpoints, fn ($bp) => $bp <= $targetWidth);

        if (! in_array($targetWidth, $sizes)) {
            $sizes[] = $targetWidth;
        }

        sort($sizes);

        return $sizes;
    }

    protected function getImageDirectory(string $path): string
    {
        $pathInfo = pathinfo($path);
        $directory = $pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] . '/' : '';
        $filename = $pathInfo['filename'];

        return $directory . $filename;
    }

    protected function buildSrcset(array $images): string
    {
        $srcset = [];

        foreach ($images as $width => $url) {
            $srcset[] = "{$url} {$width}w";
        }

        return implode(', ', $srcset);
    }

    public function clear(?string $path = null): void
    {
        $outputDisk = config('responsive-images.output_disk');
        $outputPath = config('responsive-images.output_path');

        if ($path) {
            $imageDirectory = $this->getImageDirectory($path);
            $fullPath = "{$outputPath}/{$imageDirectory}";

            if (Storage::disk($outputDisk)->exists($fullPath)) {
                Storage::disk($outputDisk)->deleteDirectory($fullPath);
            }
        } else {
            if (Storage::disk($outputDisk)->exists($outputPath)) {
                Storage::disk($outputDisk)->deleteDirectory($outputPath);
            }
        }
    }
}
