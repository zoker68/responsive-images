# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

`zoker/responsive-images` (`Zoker\ResponsiveImages\`) — note the directory is `images/` but the package/namespace is **ResponsiveImages**. Generates responsive WebP images at multiple resolutions via Intervention Image (v3/v4), with disk caching and async generation. Independent of the other `zoker/*` packages. Developed inside a host workbench app where it is symlinked into `vendor/` as a Composer `path` repo; unlike the siblings it **does ship its own `vendor/`**. Standalone, independently publishable.

## Commands

PHPUnit suite in `tests/` (Orchestra Testbench). Run it on both Intervention versions: the workbench root has v3, the package's own `vendor/` has v4.

```bash
./vendor/bin/sail composer test:images                               # from the root: intervention/image v3
cd packages/zoker/images && php vendor/bin/phpunit                    # package vendor: intervention/image v4
cd packages/zoker/images && composer analyze                          # phpstan (needs the package vendor)
./vendor/bin/sail php vendor/bin/phpstan analyse -c packages/zoker/images/phpstan.neon
./vendor/bin/sail pint packages/zoker/images
```

## Architecture

One provider: **`ResponsiveImagesServiceProvider`** (Spatie `PackageServiceProvider`) — registers config, views, the `ClearCommand`; binds `ResponsiveImagesService` as a **singleton**; registers the `responsive-image` Blade component and `@responsiveImage` directive.

`src/`:
- **`ResponsiveImagesService`** — core. `make()` (lazy: returns a fallback immediately, dispatches a job to build the rest), `generate()` (full synchronous generation), `clear()`. Computes sizes from breakpoints; supports Intervention Image v3 and v4.
- **`ResponsiveImage`** (DTO) — `src`, `generatedImages`, `sizes`, `width`, `height`, `format`, `toHtml()`.
- **`Jobs/GenerateResponsiveImages`** — async generation onto the queue.
- **`Commands/ClearCommand`**, **`Facades/ResponsiveImages`**, **`View/Components/ResponsiveImage`** (renders `<img>` + `srcset`).

## Key convention

`make()` is **fallback-first**: on the first request for an image it returns the original/WebP fallback and queues `GenerateResponsiveImages` to build cached resolutions in the background; subsequent requests serve the cache. The cache key is `MD5(disk, path, width, height)` and honors a flexible stale/expire TTL from config. Use `ClearCommand` to invalidate.

Only extensions from `responsive-images.extensions` (jpg/jpeg, png, webp, gif, avif, bmp, tif/tiff, heic/heif) that the configured driver (`driver`: imagick if ext-imagick is loaded, else gd; enum `ImageDriver`) can read on this server (`driverSupports()`: `imagetypes()` for GD, `Imagick::queryFormats()` for Imagick — lists TIFF but not TIF; not Intervention's `Driver::supports()`, which is 3.6+ only) are processed. Non-browser formats (tif/heic) get a synchronous full-size WebP as fallback (`BROWSER_EXTENSIONS`); anything else (svg) is returned as the original URL with no job and no `<source>`.

Tests pin `driver=gd` in `TestCase` (the default depends on ext-imagick); `ImagickDriverTest` switches to imagick and skips on the host (no ext-imagick). Run it in the Sail image: `docker compose run --rm --no-deps -T --user $(id -u):$(id -g) --entrypoint php laravel.test vendor/bin/phpunit -c packages/zoker/images/phpunit.xml --bootstrap vendor/autoload.php`. `<source>` is rendered only via `ResponsiveImage::hasSource()` (generated images in the output format). Intervention calls must work on both v3 and v4 (v4: `decodeBinary()`/`createImage()`; `encode(new WebpEncoder)` works on both).

Changes here are real package changes — they must land in this package's upstream repo.