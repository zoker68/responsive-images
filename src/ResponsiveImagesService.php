<?php

namespace Zoker\ResponsiveImages;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;
use Zoker\ResponsiveImages\Enums\ImageDriver;
use Zoker\ResponsiveImages\Jobs\GenerateResponsiveImages;

class ResponsiveImagesService
{
    /**
     * Extensions browsers can display, so the untouched original is a usable fallback.
     */
    protected const BROWSER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];

    protected ImageDriver $driver;

    protected ImageManager $manager;

    /** @var array<int, string>|null */
    protected ?array $imagickFormats = null;

    public function __construct()
    {
        $this->driver = ImageDriver::from(config('responsive-images.driver', ImageDriver::Gd->value));
        $this->manager = new ImageManager($this->driver->instance());
    }

    /**
     * Build a ResponsiveImage from cached files. If anything is missing, generate it in the
     * request (sync queue or queue => false) or dispatch a job and return a fallback.
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

        $data = $this->cache()->flexible(
            $cacheKey,
            [$staleSeconds, $expireSeconds],
            function () use ($path, $width, $height, $disk) {
                $image = $this->resolve($path, $width, $height, $disk);

                return $image === null ? null : [
                    'src' => $image->src,
                    'generatedImages' => $image->generatedImages,
                    'sizes' => $image->sizes,
                    'width' => $image->width,
                    'height' => $image->height,
                    'format' => $image->format,
                ];
            }
        );

        return $data === null ? null : new ResponsiveImage(
            src: $data['src'],
            generatedImages: $data['generatedImages'],
            sizes: $data['sizes'],
            width: $data['width'],
            height: $data['height'],
            format: $data['format'],
        );
    }

    /**
     * Render the <picture> markup, or an empty string when the source is missing. Backs the @responsiveImage directive.
     */
    public function render(
        ?string $path,
        ?int $width = null,
        ?int $height = null,
        ?string $disk = null,
        string $alt = '',
        string $loading = 'lazy',
        ?string $sizes = null
    ): string {
        return $this->make($path, $width, $height, $disk)?->toHtml($alt, $loading, [], $sizes) ?? '';
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

        if (! $this->isSupported($path)) {
            return new ResponsiveImage(
                src: Storage::disk($disk)->url($path),
                generatedImages: [],
                sizes: '100vw',
                width: $width ?? 0,
                height: $height ?? 0,
                format: $this->extension($path)
            );
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

        $generationFailed = false;

        if (! $allCached) {
            if ($this->generatesSynchronously()) {
                try {
                    return $this->generate($path, $width, $height, $disk);
                } catch (Throwable $e) {
                    report($e);
                    $generationFailed = true;
                }
            } else {
                $this->dispatchJob($path, $width, $height, $disk);
            }
        }

        if (empty($generatedImages)) {
            if (! $generationFailed && ! in_array($this->extension($path), static::BROWSER_EXTENSIONS, true)) {
                $this->ensureDisplayableFallback($ctx);
            }

            $fallback = $this->getFallback($ctx);

            return new ResponsiveImage(
                src: $fallback['url'],
                generatedImages: [],
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

        if (! Storage::disk($disk)->exists($path) || ! $this->isSupported($path)) {
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
     * Convert the original synchronously so that a browser has something to show before the job runs.
     */
    protected function ensureDisplayableFallback(array $ctx): void
    {
        try {
            $this->ensureOriginalWebp($ctx, $this->readOriginal($ctx['disk'], $ctx['path']));
        } catch (Throwable $e) {
            report($e);
        }
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
        $binary = Storage::disk($disk)->get($path);

        // v4 uses decodeBinary(), v3 uses read()
        // @phpstan-ignore-next-line (supports both v3 and v4)
        if (method_exists($this->manager, 'decodeBinary')) {
            return $this->manager->decodeBinary($binary);
        }

        // @phpstan-ignore-next-line (read() exists in v3)
        return $this->manager->read($binary);
    }

    protected function resize($image, int $width, ?int $height)
    {
        // @phpstan-ignore-next-line (supports both v3 and v4)
        if (method_exists($image, 'cover')) {
            // v4
            if ($height !== null) {
                $image->cover($width, $height);
            } else {
                // @phpstan-ignore-next-line (supports both v3 and v4)
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
        return $image->encode(new WebpEncoder(quality: $quality));
    }

    protected function outputDisk(array $ctx): Filesystem
    {
        return Storage::disk($ctx['outputDisk']);
    }

    protected function generatesSynchronously(): bool
    {
        if (config('responsive-images.queue') === false) {
            return true;
        }

        $connection = config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
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

    protected function isSupported(string $path): bool
    {
        $extension = $this->extension($path);
        $extensions = array_map('strtolower', config('responsive-images.extensions', []));

        return in_array($extension, $extensions, true) && $this->driverSupports($extension);
    }

    /**
     * Library builds differ (e.g. AVIF needs libavif, HEIC needs libheif), so a configured extension may still be unreadable.
     */
    protected function driverSupports(string $extension): bool
    {
        return match ($this->driver) {
            ImageDriver::Gd => $this->gdSupports($extension),
            ImageDriver::Imagick => $this->imagickSupports($extension),
        };
    }

    protected function imagickSupports(string $extension): bool
    {
        $this->imagickFormats ??= \Imagick::queryFormats();
        // ImageMagick lists TIFF but not the tif alias
        $format = $extension === 'tif' ? 'TIFF' : strtoupper($extension);

        return in_array($format, $this->imagickFormats, true);
    }

    protected function gdSupports(string $extension): bool
    {
        $type = match ($extension) {
            'jpg', 'jpeg' => IMG_JPEG,
            'png' => IMG_PNG,
            'gif' => IMG_GIF,
            'webp' => IMG_WEBP,
            'avif' => IMG_AVIF,
            'bmp' => IMG_BMP,
            default => 0,
        };

        return (imagetypes() & $type) !== 0;
    }

    protected function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    protected function getImageDirectory(string $path): string
    {
        $info = pathinfo($path);
        $directory = $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';

        return $directory . $info['filename'];
    }
}
