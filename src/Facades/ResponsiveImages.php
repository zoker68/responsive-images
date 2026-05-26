<?php

namespace Zoker\ResponsiveImages\Facades;

use Illuminate\Support\Facades\Facade;

class ResponsiveImages extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'responsive-images';
    }
}
