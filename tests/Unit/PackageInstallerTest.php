<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Update\PackageInstaller;
use App\Update\Release;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PackageInstallerTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->dir = sys_get_temp_dir().'/sf-update-test-'.bin2hex(random_bytes(4));
        $this->fs->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testDownloadChecksTheChecksum(): void
    {
        $zip = $this->makePackage(['bin/console' => 'new', 'src/Kernel.php' => 'new']);
        $bytes = (string) file_get_contents($zip);
        $release = new Release('2.0.0', '', null, '', 'https://example.test/p.zip', 'https://example.test/p.zip.sha256');

        $good = new PackageInstaller(new MockHttpClient([new MockResponse(hash('sha256', $bytes)."  p.zip\n"), new MockResponse($bytes)]));
        self::assertFileExists($good->download($release, $this->dir.'/dl'));

        $bad = new PackageInstaller(new MockHttpClient([new MockResponse(str_repeat('a', 64)), new MockResponse($bytes)]));
        $this->expectExceptionMessage('does not match');
        $bad->download($release, $this->dir.'/dl2');
    }

    public function testSwapKeepsDataAndSettingsAndCanRollBack(): void
    {
        $project = $this->dir.'/project';
        $this->fs->dumpFile($project.'/src/Kernel.php', 'old kernel');
        $this->fs->dumpFile($project.'/src/Removed.php', 'old only');
        $this->fs->dumpFile($project.'/.env.local', 'SECRET=keep');
        $this->fs->dumpFile($project.'/data/uploads/photo.jpg', 'media');
        $this->fs->dumpFile($project.'/var/sessions/s', 'session');

        $installer = new PackageInstaller(new MockHttpClient());
        $zip = $this->makePackage([
            'bin/console' => 'new console',
            'src/Kernel.php' => 'new kernel',
            'src/Added.php' => 'new only',
            '.env.local' => 'SECRET=from-package', // must be ignored
            'data/uploads/photo.jpg' => 'overwritten', // must be ignored
        ]);
        $root = $installer->extract($zip, $this->dir.'/extract');
        $replaced = $installer->swap($root, $project, $this->dir.'/previous');

        self::assertStringEqualsFile($project.'/src/Kernel.php', 'new kernel');
        self::assertFileExists($project.'/src/Added.php');
        self::assertFileDoesNotExist($project.'/src/Removed.php');
        self::assertStringEqualsFile($project.'/.env.local', 'SECRET=keep');
        self::assertStringEqualsFile($project.'/data/uploads/photo.jpg', 'media');
        self::assertFileExists($project.'/var/sessions/s');

        $installer->rollback($replaced, $project, $this->dir.'/previous');
        self::assertStringEqualsFile($project.'/src/Kernel.php', 'old kernel');
        self::assertFileExists($project.'/src/Removed.php');
        self::assertFileDoesNotExist($project.'/src/Added.php');
        self::assertFileDoesNotExist($project.'/bin/console');
    }

    public function testAFolderThatCannotMoveIsUpdatedFileByFile(): void
    {
        // Like Windows with the running bin/console: the bin folder and that
        // file cannot be moved; the file is identical in the new version.
        $project = $this->dir.'/project';
        $this->fs->dumpFile($project.'/bin/console', 'same console');
        $this->fs->dumpFile($project.'/bin/old-tool', 'removed in new version');
        $this->fs->dumpFile($project.'/src/Kernel.php', 'old kernel');

        $installer = new class(new MockHttpClient()) extends PackageInstaller {
            protected function move(string $from, string $to): bool
            {
                $locked = strtr($from, DIRECTORY_SEPARATOR, '/');
                if (str_ends_with($locked, 'project/bin') || str_ends_with($locked, 'project/bin/console')) {
                    return false;
                }

                return parent::move($from, $to);
            }
        };
        $zip = $this->makePackage(['bin/console' => 'same console', 'bin/new-tool' => 'new', 'src/Kernel.php' => 'new kernel']);
        $root = $installer->extract($zip, $this->dir.'/extract');
        $journal = $installer->swap($root, $project, $this->dir.'/previous');

        self::assertStringEqualsFile($project.'/bin/console', 'same console');
        self::assertFileExists($project.'/bin/new-tool');
        self::assertFileDoesNotExist($project.'/bin/old-tool');
        self::assertStringEqualsFile($project.'/src/Kernel.php', 'new kernel');

        self::assertSame([], $installer->rollback($journal, $project, $this->dir.'/previous'));
        self::assertStringEqualsFile($project.'/src/Kernel.php', 'old kernel');
        self::assertFileExists($project.'/bin/old-tool');
        self::assertFileDoesNotExist($project.'/bin/new-tool');
        self::assertStringEqualsFile($project.'/bin/console', 'same console');
    }

    public function testALockedFileThatChangesFailsAndRollsBack(): void
    {
        $project = $this->dir.'/project';
        $this->fs->dumpFile($project.'/bin/console', 'old console');
        $this->fs->dumpFile($project.'/src/Kernel.php', 'old kernel');

        $installer = new class(new MockHttpClient()) extends PackageInstaller {
            protected function move(string $from, string $to): bool
            {
                $locked = strtr($from, DIRECTORY_SEPARATOR, '/');

                return !str_ends_with($locked, 'project/bin') && !str_ends_with($locked, 'project/bin/console') && parent::move($from, $to);
            }
        };
        $zip = $this->makePackage(['bin/console' => 'new console', 'src/Kernel.php' => 'new kernel']);
        $root = $installer->extract($zip, $this->dir.'/extract');

        try {
            $installer->swap($root, $project, $this->dir.'/previous');
            self::fail('The swap should fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('previous version was restored', $e->getMessage());
        }
        self::assertStringEqualsFile($project.'/bin/console', 'old console');
        self::assertStringEqualsFile($project.'/src/Kernel.php', 'old kernel');
    }

    public function testPackagesWithUnsafePathsAreRefused(): void
    {
        $zip = $this->makePackage(['bin/console' => 'x', 'src/Kernel.php' => 'x', '../escape.php' => 'evil']);

        $this->expectExceptionMessage('unsafe path');
        (new PackageInstaller(new MockHttpClient()))->extract($zip, $this->dir.'/extract');
    }

    public function testSomethingElseIsNotInstalled(): void
    {
        $zip = $this->makePackage(['README.md' => 'not us']);

        $this->expectExceptionMessage('does not look like Signage Forge');
        (new PackageInstaller(new MockHttpClient()))->extract($zip, $this->dir.'/extract');
    }

    /** @param array<string, string> $files */
    private function makePackage(array $files): string
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString('signageforge/'.$name, $content);
        }
        $zip->close();

        return $path;
    }
}
