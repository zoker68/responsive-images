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
