# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

`zoker/responsive-images` (`Zoker\ResponsiveImages\`) — note the directory is `images/` but the package/namespace is **ResponsiveImages**. Generates responsive WebP images at multiple resolutions via Intervention Image (v3/v4), with disk caching and async generation. Independent of the other `zoker/*` packages. Developed inside a host workbench app where it is symlinked into `vendor/` as a Composer `path` repo; unlike the siblings it **does ship its own `vendor/`**. Standalone, independently publishable.

## Commands

From the host root, via Sail. **No tests exist yet** in this package.

```bash
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

Changes here are real package changes — they must land in this package's upstream repo.