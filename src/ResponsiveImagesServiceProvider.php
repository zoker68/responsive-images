<?php

namespace Zoker\ResponsiveImages;

use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zoker\ResponsiveImages\Commands\ClearCommand;
use Zoker\ResponsiveImages\View\Components\ResponsiveImage;

class ResponsiveImagesServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('responsive-images')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([
                ClearCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('responsive-images', function ($app) {
            return new ResponsiveImagesService;
        });
    }

    public function packageBooted(): void
    {
        Blade::component('responsive-image', ResponsiveImage::class);

        Blade::directive('responsiveImage', function ($expression) {
            return "<?php
                \$__responsiveImageArgs = [{$expression}];
                \$__alt = \$__responsiveImageArgs['alt'] ?? '';
                \$__loading = \$__responsiveImageArgs['loading'] ?? 'lazy';
                unset(\$__responsiveImageArgs['alt'], \$__responsiveImageArgs['loading']);
                \$__responsiveImage = app('responsive-images')->make(...array_values(\$__responsiveImageArgs));
                if (\$__responsiveImage) {
                    echo \$__responsiveImage->toHtml(\$__alt, \$__loading);
                }
            ?>";
        });
    }
}
