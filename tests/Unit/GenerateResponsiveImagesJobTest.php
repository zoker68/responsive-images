<?php

declare(strict_types=1);

namespace Zoker\ResponsiveImages\Tests\Unit;

use Mockery;
use Zoker\ResponsiveImages\Jobs\GenerateResponsiveImages;
use Zoker\ResponsiveImages\ResponsiveImagesService;
use Zoker\ResponsiveImages\Tests\TestCase;

class GenerateResponsiveImagesJobTest extends TestCase
{
    public function test_unique_id_is_built_from_disk_path_and_dimensions(): void
    {
        $job = new GenerateResponsiveImages('photos/a.jpg', 320, 200, 's3');

        $this->assertEquals('s3|photos/a.jpg|320|200', $job->uniqueId());
    }

    public function test_unique_id_handles_missing_optional_values(): void
    {
        $job = new GenerateResponsiveImages('photos/a.jpg');

        $this->assertEquals('|photos/a.jpg||', $job->uniqueId());
    }

    public function test_handle_generates_and_forgets_the_cache(): void
    {
        $service = Mockery::mock(ResponsiveImagesService::class);
        $service->shouldReceive('generate')->once()->with('photos/a.jpg', 320, null, null);
        $service->shouldReceive('forgetCache')->once()->with('photos/a.jpg', 320, null, null);

        (new GenerateResponsiveImages('photos/a.jpg', 320))->handle($service);
    }
}
