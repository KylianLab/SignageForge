<?php

declare(strict_types=1);

namespace App\Update;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a release package, checks it, and swaps it in place of the
 * current code. The site's own data never moves: settings (.env.local and
 * variants), var/ (sessions, cache, backups) and data/ (media, SQLite).
 * Replaced entries are kept aside so a failed update can be rolled back.
 */
final class PackageInstaller
{
    /** Top-level entries an update must never replace. */
    public const PROTECTED = ['.env.local', '.env.local.php', '.env.prod.local', '.env.dev.local', 'var', 'data', '.git'];

    private readonly Filesystem $filesystem;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Downloads the package into $workDir and checks its SHA-256.
     *
     * @throws \RuntimeException when the download fails or does not match
     */
    public function download(Release $release, string $workDir): string
    {
        if (!$release->hasPackage()) {
            throw new \RuntimeException(\sprintf('Release %s has no ready-to-install package.', $release->version));
        }

        $this->filesystem->mkdir($workDir);
        $zip = $workDir.'/signageforge-'.$release->version.'.zip';

        $expected = strtolower(substr(trim($this->httpClient->request('GET', (string) $release->checksumUrl)->getContent()), 0, 64));
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            throw new \RuntimeException('The published checksum is not a SHA-256.');
        }

        $file = fopen($zip, 'w');
        if (false === $file) {
            throw new \RuntimeException('Cannot write '.$zip);
        }
        try {
            foreach ($this->httpClient->stream($this->httpClient->request('GET', (string) $release->packageUrl, ['timeout' => 300])) as $chunk) {
                fwrite($file, $chunk->getContent());
            }
        } finally {
            fclose($file);
        }

        $this->verify($zip, $expected);

        return $zip;
    }

    public function verify(string $zip, string $expectedSha256): void
    {
        if (!hash_equals($expectedSha256, (string) hash_file('sha256', $zip))) {
            $this->filesystem->remove($zip);
            throw new \RuntimeException('The downloaded package does not match its published checksum. Nothing was changed.');
        }
    }

    /** Unpacks the package and returns the folder that holds the application. */
    public function extract(string $zip, string $targetDir): string
    {
        $this->filesystem->remove($targetDir);
        $this->filesystem->mkdir($targetDir);

        $archive = new \ZipArchive();
        if (true !== $archive->open($zip)) {
            throw new \RuntimeException('The package is not a valid zip file.');
        }
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $name = (string) $archive->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, ':')) {
                $archive->close();
                throw new \RuntimeException('The package contains an unsafe path: '.$name);
            }
        }
        $archive->extractTo($targetDir);
        $archive->close();

        // Accept both a flat package and one wrapped in a single folder.
        $entries = array_values(array_diff(scandir($targetDir) ?: [], ['.', '..']));
        $root = 1 === \count($entries) && is_dir($targetDir.'/'.$entries[0]) ? $targetDir.'/'.$entries[0] : $targetDir;
        if (!is_file($root.'/bin/console') || !is_file($root.'/src/Kernel.php')) {
            throw new \RuntimeException('The package does not look like Signage Forge.');
        }

        return $root;
    }

    /**
     * Moves the new code in place. Each replaced entry goes to $backupDir
     * first; on any error, everything moved so far is put back.
     *
     * @return list<string> the entries that were replaced
     */
    public function swap(string $newRoot, string $projectDir, string $backupDir): array
    {
        $this->filesystem->remove($backupDir);
        $this->filesystem->mkdir($backupDir);
        $moved = [];

        try {
            foreach (array_diff(scandir($newRoot) ?: [], ['.', '..']) as $entry) {
                if (\in_array($entry, self::PROTECTED, true)) {
                    continue;
                }
                $current = $projectDir.'/'.$entry;
                if (file_exists($current)) {
                    $this->filesystem->rename($current, $backupDir.'/'.$entry);
                }
                $moved[] = $entry;
                $this->filesystem->rename($newRoot.'/'.$entry, $current);
            }
        } catch (\Throwable $e) {
            $this->rollback($moved, $projectDir, $backupDir);
            throw new \RuntimeException('Installing the new files failed, the previous version was restored: '.$e->getMessage(), 0, $e);
        }

        return $moved;
    }

    /** @param list<string> $entries */
    public function rollback(array $entries, string $projectDir, string $backupDir): void
    {
        foreach (array_reverse($entries) as $entry) {
            $current = $projectDir.'/'.$entry;
            if (file_exists($backupDir.'/'.$entry)) {
                $this->filesystem->remove($current);
                $this->filesystem->rename($backupDir.'/'.$entry, $current);
            } elseif (file_exists($current)) {
                $this->filesystem->remove($current); // new in this release
            }
        }
    }
}
