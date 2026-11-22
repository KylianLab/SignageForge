<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Maintenance\MaintenanceMode;
use App\Service\DatabaseBackup;
use App\Service\MediaStorage;
use App\Service\TenantContext;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MaintenanceTest extends FunctionalTestCase
{
    protected function tearDown(): void
    {
        static::getContainer()->get(MaintenanceMode::class)->disable();
        parent::tearDown();
    }

    public function testDuringMaintenancePagesWaitButScreensKeepTheirMedia(): void
    {
        $path = sys_get_temp_dir().'/maintenance.png';
        imagepng(imagecreatetruecolor(4, 4), $path);
        $key = static::getContainer()->get(MediaStorage::class)->store(new UploadedFile($path, 'slide.png', test: true));
        $media = new Media(static::getContainer()->get(TenantContext::class)->getOrganization(), 'slide', MediaType::Image, $key);
        $media->setMimeType('image/png');
        $this->em()->persist($media);
        $this->em()->flush();

        static::getContainer()->get(MaintenanceMode::class)->enable();

        $this->client->request('GET', '/dashboard');
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '60');
        self::assertSelectorTextContains('h1', 'being updated');

        $this->client->request('GET', '/api/player/some-device-token-1234');
        self::assertResponseStatusCodeSame(503);

        $this->client->request('GET', '/api/media/file/'.$media->getId());
        self::assertResponseIsSuccessful();

        static::getContainer()->get(MaintenanceMode::class)->disable();
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }

    public function testSqliteDatabasesAreCopiedBeforeAnUpdate(): void
    {
        $backup = static::getContainer()->get(DatabaseBackup::class);

        self::assertTrue($backup->supported());
        $copy = $backup->backup();
        self::assertNotNull($copy);
        self::assertFileExists($copy);
        self::assertGreaterThan(0, filesize($copy));
        unlink($copy);
    }
}
