<?php

namespace Zoker\ResponsiveImages\View\Components;

use Illuminate\View\Component;
use Zoker\ResponsiveImages\ResponsiveImagesService;

class ResponsiveImage extends Component
{
    public ?\Zoker\ResponsiveImages\ResponsiveImage $image;

    public function __construct(
        public string $path,
        public ?int $width = null,
        public ?int $height = null,
        public string $alt = '',
        public string $loading = 'lazy',
        public ?string $disk = null
    ) {
        $service = app(ResponsiveImagesService::class);
        $this->image = $service->make($this->path, $this->width, $this->height, $this->disk);
    }

    public function shouldRender(): bool
    {
        return $this->image !== null;
    }

    public function render()
    {
        return view('responsive-images::components.responsive-image');
    }
}
