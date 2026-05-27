<?php

namespace Zoker\ResponsiveImages;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Zoker\ResponsiveImages\Jobs\GenerateResponsiveImages;

class ResponsiveImagesService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Build a ResponsiveImage from cached files. If anything is missing,
     * dispatch a job to generate it and return a fallback (webp original or source).
     */
    public function make(
        ?string $path,
        ?int $width = null,
        ?int $height = null,
        ?string $disk = null
    ): ?ResponsiveImage {
        if ($path === null) {
            return null;
        }

        $disk = $disk ?? config('responsive-images.disk');
        [$staleSeconds, $expireSeconds] = config('responsive-images.cache_ttl', [300, 86400]);
        $cacheKey = $this->cacheKey($path, $width, $height, $disk);

        return $this->cache()->flexible(
            $cacheKey,
            [$staleSeconds, $expireSeconds],
            fn () => $this->resolve($path, $width, $height, $disk)
        );
    }

    /**
     * Forget the cached make() result for given parameters.
     */
    public function forgetCache(string $path, ?int $width, ?int $height, ?string $disk): void
    {
        $disk = $disk ?? config('responsive-images.disk');
        $this->cache()->forget($this->cacheKey($path, $width, $height, $disk));
    }

    protected function resolve(string $path, ?int $width, ?int $height, string $disk): ?ResponsiveImage
    {
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $ctx = $this->buildContext($path, $disk);
        $sizes = $width !== null
            ? $this->calculateSizes($width)
            : config('responsive-images.breakpoints', []);

        $generatedImages = [];
        $allCached = true;

        foreach ($sizes as $size) {
            $url = $this->getCachedSizeUrl($ctx, $size, $width, $height);

            if ($url !== null) {
                $generatedImages[$size] = $url;
            } else {
                $allCached = false;
            }
        }

        if (! $allCached) {
            $this->dispatchJob($path, $width, $height, $disk);
        }

        if (empty($generatedImages)) {
            $fallback = $this->getFallback($ctx);

            return new ResponsiveImage(
                src: $fallback['url'],
                generatedImages: [($width ?? 0) => $fallback['url']],
                sizes: '100vw',
                width: $width ?? 0,
                height: $height ?? 0,
                format: $fallback['format']
            );
        }

        return new ResponsiveImage(
            src: end($generatedImages),
            generatedImages: $generatedImages,
            sizes: '100vw',
            width: $width ?? 0,
            height: $height ?? 0,
            format: $ctx['format']
        );
    }

    protected function cache(): Repository
    {
        $store = config('responsive-images.cache_store');

        return $store ? Cache::store($store) : Cache::store();
    }

    protected function cacheKey(string $path, ?int $width, ?int $height, string $disk): string
    {
        return 'responsive-images:' . md5(implode('|', [$disk, $path, $width ?? '', $height ?? '']));
    }

    /**
     * Generate webp original and all responsive sizes. Used by the queue job.
     */
    public function generate(
        ?string $path,
        ?int $width = null,
        ?int $height = null,
        ?string $disk = null
    ): ?ResponsiveImage {
        if ($path === null) {
            return null;
        }

        $disk = $disk ?? config('responsive-images.disk');

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $ctx = $this->buildContext($path, $disk);
        $original = $this->readOriginal($disk, $path);

        $explicitHeight = $height;
        $width = $width ?? $original->width();
        $height = $height ?? (int) round($width * ($original->height() / $original->width()));

        $this->ensureOriginalWebp($ctx, $original);

        $sizes = $this->calculateSizes($width);
        $generatedImages = [];

        foreach ($sizes as $size) {
            $resizedHeight = (int) round($size * ($height / $width));
            $generatedImages[$size] = $this->ensureSizeFile(
                $ctx, $original, $size, $width, $explicitHeight !== null ? $height : null, $resizedHeight
            );
        }

        return new ResponsiveImage(
            src: end($generatedImages),
            generatedImages: $generatedImages,
            sizes: '100vw',
            width: $width,
            height: $height,
            format: $ctx['format']
        );
    }

    public function clear(?string $path = null): void
    {
        $outputDisk = config('responsive-images.output_disk');
        $outputPath = config('responsive-images.output_path');
        $target = $path ? "{$outputPath}/{$this->getImageDirectory($path)}" : $outputPath;

        if (Storage::disk($outputDisk)->exists($target)) {
            Storage::disk($outputDisk)->deleteDirectory($target);
        }

        $this->cache()->clear();
    }

    /**
     * Build common context: paths, config and metadata used by both make() and generate().
     *
     * @return array{disk:string,path:string,outputDisk:string,fullOutputPath:string,filename:string,lastModified:int,quality:int,format:string}
     */
    protected function buildContext(string $path, string $disk): array
    {
        $outputPath = config('responsive-images.output_path');
        $filename = pathinfo($path, PATHINFO_FILENAME);

        return [
            'disk' => $disk,
            'path' => $path,
            'outputDisk' => config('responsive-images.output_disk'),
            'fullOutputPath' => "{$outputPath}/{$this->getImageDirectory($path)}",
            'filename' => $filename,
            'lastModified' => Storage::disk($disk)->lastModified($path),
            'quality' => config('responsive-images.quality'),
            'format' => config('responsive-images.format'),
        ];
    }

    /**
     * Return URL of cached resized file or null if it doesn't exist.
     */
    protected function getCachedSizeUrl(array $ctx, int $size, ?int $width, ?int $height): ?string
    {
        $resizedHeight = ($width !== null && $height !== null)
            ? (int) round($size * ($height / $width))
            : null;

        $filePath = $this->buildSizePath($ctx, $size, $resizedHeight);
        $output = $this->outputDisk($ctx);

        return $output->exists($filePath) ? $output->url($filePath) : null;
    }

    /**
     * Get fallback URL: webp original if cached, otherwise source file.
     *
     * @return array{url:string,format:string}
     */
    protected function getFallback(array $ctx): array
    {
        $webpPath = $this->buildOriginalWebpPath($ctx);
        $output = $this->outputDisk($ctx);

        if ($output->exists($webpPath)) {
            return ['url' => $output->url($webpPath), 'format' => $ctx['format']];
        }

        return [
            'url' => Storage::disk($ctx['disk'])->url($ctx['path']),
            'format' => pathinfo($ctx['path'], PATHINFO_EXTENSION),
        ];
    }

    /**
     * Ensure webp version of the original image exists in cache.
     */
    protected function ensureOriginalWebp(array $ctx, $original): void
    {
        $filePath = $this->buildOriginalWebpPath($ctx);
        $output = $this->outputDisk($ctx);

        if ($output->exists($filePath)) {
            return;
        }

        $output->put($filePath, (string) $this->encodeWebp(clone $original, $ctx['quality']));
    }

    /**
     * Ensure resized file exists; create it if missing. Returns its public URL.
     */
    protected function ensureSizeFile(array $ctx, $original, int $size, int $width, ?int $height, int $resizedHeight): string
    {
        $filePath = $this->buildSizePath($ctx, $size, $height !== null ? $resizedHeight : null);
        $output = $this->outputDisk($ctx);

        if (! $output->exists($filePath)) {
            $resized = $this->resize(clone $original, $size, $height !== null ? $resizedHeight : null);
            $output->put($filePath, (string) $this->encodeWebp($resized, $ctx['quality']));
        }

        return $output->url($filePath);
    }

    protected function buildOriginalWebpPath(array $ctx): string
    {
        $hash = md5(implode('|', [$ctx['lastModified'], $ctx['quality'], $ctx['format']]));

        return "{$ctx['fullOutputPath']}/{$ctx['filename']}-original-{$hash}.{$ctx['format']}";
    }

    protected function buildSizePath(array $ctx, int $size, ?int $resizedHeight): string
    {
        $hashParts = [$ctx['lastModified'], $size, $ctx['quality'], $ctx['format']];

        if ($resizedHeight !== null) {
            $hashParts[] = $resizedHeight;
        }

        $hash = md5(implode('|', $hashParts));

        return "{$ctx['fullOutputPath']}/{$ctx['filename']}-{$size}-{$hash}.{$ctx['format']}";
    }

    protected function readOriginal(string $disk, string $path)
    {
        // v4 uses read(), v3 uses make()
        // @phpstan-ignore-next-line (supports both v3 and v4)
        if (method_exists($this->manager, 'read')) {
            return $this->manager->read(Storage::disk($disk)->get($path));
        }

        // @phpstan-ignore-next-line (make() exists in v3)
        return $this->manager->make(Storage::disk($disk)->path($path));
    }

    protected function resize($image, int $width, ?int $height)
    {
        // @phpstan-ignore-next-line (supports both v3 and v4)
        if (method_exists($image, 'cover')) {
            // v4
            if ($height !== null) {
                $image->cover($width, $height);
            } else {
                $image->scale(width: $width);
            }
        } else {
            // v3
            if ($height !== null) {
                // @phpstan-ignore-next-line (v3 method)
                $image->fit($width, $height);
            } else {
                // @phpstan-ignore-next-line (v3 method)
                $image->resize($width, null, fn ($c) => $c->aspectRatio());
            }
        }

        return $image;
    }

    protected function encodeWebp($image, int $quality)
    {
        // @phpstan-ignore-next-line (supports both v3 and v4)
        return method_exists($image, 'toWebp')
            ? $image->toWebp($quality)
            : $image->encode('webp', $quality); // @phpstan-ignore-line
    }

    protected function outputDisk(array $ctx): Filesystem
    {
        return Storage::disk($ctx['outputDisk']);
    }

    protected function dispatchJob(string $path, ?int $width, ?int $height, ?string $disk): void
    {
        $job = new GenerateResponsiveImages($path, $width, $height, $disk);

        if ($queue = config('responsive-images.queue')) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    protected function calculateSizes(int $targetWidth): array
    {
        $sizes = array_filter(
            config('responsive-images.breakpoints', []),
            fn ($bp) => $bp <= $targetWidth
        );

        if (! in_array($targetWidth, $sizes)) {
            $sizes[] = $targetWidth;
        }

        sort($sizes);

        return $sizes;
    }

    protected function getImageDirectory(string $path): string
    {
        $info = pathinfo($path);
        $directory = $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';

        return $directory . $info['filename'];
    }
}
