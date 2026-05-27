# Responsive Images for Laravel

Laravel package for automatic image conversion to WebP and responsive sizes generation.

## Installation

```bash
composer require zoker/responsive-images
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="responsive-images-config"
```

## Usage

### Facade

```php
use Zoker\ResponsiveImages\Facades\ResponsiveImages;

// With specific dimensions
$image = ResponsiveImages::make(
    'uploads/products/image.jpg',
    width: 1200,
    height: 800,
    disk: 'public',
);

// Or use original image dimensions
$image = ResponsiveImages::make(
    'uploads/products/image.jpg',
    disk: 'public',
);

// Default: lazy loading
echo $image->toHtml('Product image');

// For hero images: eager loading
echo $image->toHtml('Hero image', 'eager');
```

### Blade Component

```blade
{{-- With specific dimensions --}}
<x-responsive-image
    path="uploads/products/image.jpg"
    :width="1200"
    :height="800"
    alt="Product image"
    disk="public"
/>

{{-- Or use original dimensions --}}
<x-responsive-image
    path="uploads/products/image.jpg"
    alt="Product image"
    disk="public"
/>

{{-- Hero image with eager loading --}}
<x-responsive-image
    path="uploads/hero.jpg"
    :width="1920"
    :height="1080"
    alt="Hero image"
    loading="eager"
    disk="public"
/>
```

### Blade Directive

```blade
{{-- Default: lazy loading --}}
@responsiveImage(
    'uploads/products/image.jpg',
    width: 1200,
    height: 800,
    alt: 'Product image',
    disk: 'public'
)

{{-- Hero image with eager loading --}}
@responsiveImage(
    'uploads/hero.jpg',
    width: 1920,
    height: 1080,
    alt: 'Hero image',
    loading: 'eager',
    disk: 'public'
)
```

### Working with the `ResponsiveImage` Object

`ResponsiveImages::make()` returns a `ResponsiveImage` object with the following methods:

```php
$image = ResponsiveImages::make('uploads/products/image.jpg', width: 1200, height: 800);

// Get all generated images as [width => url] array
$image->getImages();
// e.g. [320 => 'https://...', 640 => 'https://...', 1200 => 'https://...']

// Get the URL of the closest generated image to the given width
// Prefers equal or larger sizes; falls back to largest available if all are smaller
$image->getImage(500);
// returns URL of the nearest generated size (e.g. 640px version)

// Get srcset string
$image->getSrcset();
// e.g. "https://.../image-320-abc.webp 320w, https://.../image-640-def.webp 640w, ..."

// Render as HTML <picture> element
$image->toHtml('Alt text');
$image->toHtml('Alt text', 'eager');
$image->toHtml('Alt text', 'lazy', ['class' => 'my-image']);
```

Available public properties:

| Property | Type | Description |
|---|---|---|
| `$src` | `string` | URL of the largest generated image |
| `$generatedImages` | `array` | All generated images as `[width => url]` |
| `$sizes` | `string` | The `sizes` attribute value |
| `$width` | `int` | Target width |
| `$height` | `int` | Target height |
| `$format` | `string` | Output format (e.g. `webp`) |

## Artisan Commands

### Clear Generated Images

```bash
# Clear all
php artisan responsive-images:clear

# Clear for specific file
php artisan responsive-images:clear uploads/products/image.jpg
```

> **Note:** Images are automatically regenerated when the original file changes or config parameters are updated. No manual regeneration needed!

## How It Works

1. Package accepts image path, optional target width and height (uses original dimensions if not specified), Alt attribute, optional disk (uses default disk if not specified)
2. Automatically calculates responsive sizes based on breakpoints from config
3. Converts image to WebP
4. Generates versions for each size with hash-based filenames
5. Caches results using hash of (file modification time + dimensions + quality + format)
6. Automatically invalidates cache when original file or parameters change
7. Returns HTML with `<picture>` and `srcset`
