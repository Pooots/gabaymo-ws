<?php

namespace Tests\Feature;

use Tests\TestCase;

/** gabaymo-ws stores files on DigitalOcean Spaces (the "do" disk) when FILESYSTEM_DISK=do. */
class SpacesDiskTest extends TestCase
{
    public function test_spaces_disk_is_configured_and_driver_installed(): void
    {
        $disk = config('filesystems.disks.do');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertFalse($disk['use_path_style_endpoint']);
        $this->assertArrayHasKey('endpoint', $disk);
        $this->assertTrue(class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class));
        // Tests never write to the real bucket.
        $this->assertSame('local', config('filesystems.default'));
    }
}
