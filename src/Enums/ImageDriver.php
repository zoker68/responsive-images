<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Enums;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Interfaces\DriverInterface;

enum ImageDriver: string
{
    case Gd = 'gd';
    case Imagick = 'imagick';

    public function instance(): DriverInterface
    {
        return match ($this) {
            self::Gd => new GdDriver,
            self::Imagick => new ImagickDriver,
        };
    }
}
