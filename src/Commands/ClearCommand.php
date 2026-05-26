<?php

namespace Zoker\ResponsiveImages\Commands;

use Illuminate\Console\Command;
use Zoker\ResponsiveImages\ResponsiveImagesService;

class ClearCommand extends Command
{
    protected $signature = 'responsive-images:clear {path?}';

    protected $description = 'Clear generated responsive images';

    public function handle(ResponsiveImagesService $service): int
    {
        $path = $this->argument('path');

        $service->clear($path);

        if ($path) {
            $this->info("Cleared responsive images for: {$path}");
        } else {
            $this->info('Cleared all responsive images');
        }

        return self::SUCCESS;
    }
}
