<?php

namespace Zoker\ResponsiveImages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Zoker\ResponsiveImages\ResponsiveImagesService;

class GenerateResponsiveImages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $path,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $disk = null,
    ) {}

    public function uniqueId(): string
    {
        return implode('|', [$this->disk ?? '', $this->path, $this->width ?? '', $this->height ?? '']);
    }

    public function handle(ResponsiveImagesService $service): void
    {
        $service->generate($this->path, $this->width, $this->height, $this->disk);
    }
}
