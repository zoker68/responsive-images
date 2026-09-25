<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Source Disk
    |--------------------------------------------------------------------------
    |
    | The default disk where original images are stored.
    |
    */
    'disk' => env('RESPONSIVE_IMAGES_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Output Disk
    |--------------------------------------------------------------------------
    |
    | The disk where generated responsive images will be stored.
    |
    */
    'output_disk' => env('RESPONSIVE_IMAGES_OUTPUT_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Output Path
    |--------------------------------------------------------------------------
    |
    | The directory path where responsive images will be stored.
    |
    */
    'output_path' => env('RESPONSIVE_IMAGES_OUTPUT_PATH', 'responsive-images'),

    /*
    |--------------------------------------------------------------------------
    | Breakpoints
    |--------------------------------------------------------------------------
    |
    | Responsive image sizes. Only sizes smaller than or equal to the target
    | width will be generated, plus the target width itself.
    |
    */
    'breakpoints' => [
        320,
        480,
        640,
        768,
        1024,
        1280,
        1536,
        1920,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality
    |--------------------------------------------------------------------------
    |
    | WebP image quality (1-100).
    |
    */
    'quality' => env('RESPONSIVE_IMAGES_QUALITY', 85),

    /*
    |--------------------------------------------------------------------------
    | Format
    |--------------------------------------------------------------------------
    |
    | Output image format. Currently only 'webp' is supported.
    |
    */
    'format' => 'webp', // env('RESPONSIVE_IMAGES_FORMAT', 'webp'),

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Image library: 'imagick' (requires ext-imagick) or 'gd'. Defaults to
    | imagick when the extension is loaded. Imagick keeps GIF animation,
    | reads TIFF/HEIC and is faster on photos, but uses more memory, which
    | memory_limit does not cap. Run responsive-images:clear after switching:
    | generated file names do not depend on the driver.
    |
    */
    'driver' => env('RESPONSIVE_IMAGES_DRIVER', extension_loaded('imagick') ? 'imagick' : 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Supported Extensions
    |--------------------------------------------------------------------------
    |
    | Source file extensions that are converted (case-insensitive). Any other
    | file (svg, ...), or one the server's GD build cannot read, is served
    | as-is from the source disk: no job is dispatched and a plain <img> is
    | rendered. With the gd driver animated GIFs become a static WebP (first
    | frame), and tif/tiff/heic/heif need the imagick driver.
    |
    | Never add vector or document formats (svg, pdf, ps, eps): ImageMagick
    | hands them to external delegates.
    |
    */
    'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'tif', 'tiff', 'heic', 'heif'],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Queue name for background image generation jobs. Set to null to use the
    | default queue. Set to false to disable async generation entirely (sync).
    |
    */
    'queue' => env('RESPONSIVE_IMAGES_QUEUE', null),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Cache store used for the make() result. Should be a persistent backend
    | (redis, memcached, database, file). Set to null to use the default store.
    |
    */
    'cache_store' => env('RESPONSIVE_IMAGES_CACHE_STORE', env('CACHE_STORE', 'file')),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Stale-while-revalidate TTLs for make() result, in seconds.
    | [stale, expire]: up to "stale" — fresh; between "stale" and "expire" —
    | served stale and refreshed in background; after "expire" — full refresh.
    |
    */
    'cache_ttl' => [600, 86400],
];
