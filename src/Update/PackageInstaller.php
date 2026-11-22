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
class PackageInstaller
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
     * Moves the new code in place, recording every move in a journal so the
     * exact previous state can be put back.
     *
     * Entries are moved whole when possible. When the system refuses (on
     * Windows a file in use, such as the running bin/console, locks its
     * folder), the folder is handled file by file instead, and a locked file
     * that is identical in the new version is simply left where it is.
     *
     * @return list<array{string, bool}> journal: [relative path, true if it replaced something]
     */
    public function swap(string $newRoot, string $projectDir, string $backupDir): array
    {
        $this->filesystem->remove($backupDir);
        $this->filesystem->mkdir($backupDir);
        $journal = [];

        try {
            foreach (self::entries($newRoot) as $entry) {
                if (!\in_array($entry, self::PROTECTED, true)) {
                    $this->install($entry, $newRoot, $projectDir, $backupDir, $journal);
                }
            }
        } catch (\Throwable $e) {
            $problems = $this->rollback($journal, $projectDir, $backupDir);
            throw new \RuntimeException(
                ([] === $problems
                    ? 'Installing the new files failed and the previous version was restored: '
                    : 'Installing the new files failed AND restoring the previous version was incomplete ('.implode('; ', $problems).'). The previous files are in '.$backupDir.': ')
                .$e->getMessage(),
                0,
                $e,
            );
        }

        return $journal;
    }

    /**
     * Puts back what swap() moved, newest first.
     *
     * @param list<array{string, bool}> $journal
     *
     * @return list<string> what could not be restored (empty when all went well)
     */
    public function rollback(array $journal, string $projectDir, string $backupDir): array
    {
        $problems = [];
        foreach (array_reverse($journal) as [$path, $replaced]) {
            $current = $projectDir.'/'.$path;
            try {
                if (file_exists($current) || is_link($current)) {
                    $this->filesystem->remove($current);
                }
                if ($replaced && !$this->move($backupDir.'/'.$path, $current)) {
                    $problems[] = 'could not put back '.$path;
                }
            } catch (\Throwable $e) {
                $problems[] = $path.': '.$e->getMessage();
            }
        }

        return $problems;
    }

    /** @param list<array{string, bool}> $journal */
    private function install(string $path, string $newRoot, string $projectDir, string $backupDir, array &$journal): void
    {
        $new = $newRoot.'/'.$path;
        $current = $projectDir.'/'.$path;
        $backup = $backupDir.'/'.$path;

        if (!file_exists($current) && !is_link($current)) {
            $this->moveOrFail($new, $current);
            $journal[] = [$path, false];

            return;
        }
        if (is_file($current) && is_file($new) && hash_file('sha256', $current) === hash_file('sha256', $new)) {
            return; // unchanged
        }

        $this->filesystem->mkdir(\dirname($backup));
        if ($this->move($current, $backup)) {
            $journal[] = [$path, true];
            $this->moveOrFail($new, $current);

            return;
        }

        if (is_dir($current) && is_dir($new)) {
            // Could not move the folder as a whole: go through its content.
            $gone = array_diff(self::entries($current), self::entries($new));
            foreach (self::entries($new) as $child) {
                $this->install($path.'/'.$child, $newRoot, $projectDir, $backupDir, $journal);
            }
            foreach ($gone as $entry) {
                // Removed in the new version: set aside when possible.
                $this->filesystem->mkdir($backup);
                if ($this->move($current.'/'.$entry, $backup.'/'.$entry)) {
                    $journal[] = [$path.'/'.$entry, true];
                }
            }

            return;
        }

        throw new \RuntimeException(\sprintf('Cannot replace %s (in use or not writable).', $path));
    }

    /** A plain rename: no copy-and-delete fallback that could leave half-moved folders. */
    protected function move(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    private function moveOrFail(string $from, string $to): void
    {
        if (!$this->move($from, $to)) {
            throw new \RuntimeException(\sprintf('Cannot move the new %s into place.', basename($to)));
        }
    }

    /** @return list<string> */
    private static function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }
}
