<?php

declare(strict_types=1);

namespace App\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * One-click updates from the admin page. Off unless SIGNAGEFORGE_WEB_UPDATES
 * is true, because the web server must then be allowed to replace the code.
 * The update runs in its own background process (signageforge:self-update)
 * so it survives the web request; the page follows its status file.
 */
final class WebUpdater
{
    public function __construct(
        #[Autowire('%env(bool:SIGNAGEFORGE_WEB_UPDATES)%')] private readonly bool $enabled,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly UpdateStatus $status,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Why a one-click update cannot run here, or null when it can. */
    public function blocker(): ?string
    {
        return match (true) {
            !$this->enabled => 'One-click updates are off. Set SIGNAGEFORGE_WEB_UPDATES=true to allow them.',
            !class_exists(\ZipArchive::class) => 'The PHP zip extension is missing.',
            !is_writable($this->projectDir) => 'The web server cannot write the project folder.',
            $this->status->isRunning() => 'An update is already running.',
            default => null,
        };
    }

    public function start(string $version): void
    {
        if (null !== $blocker = $this->blocker()) {
            throw new \RuntimeException($blocker);
        }

        $this->status->start($version, 'Starting the update…');
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $log = $this->projectDir.'/var/update/output.log';
        $command = [$php, $this->projectDir.'/bin/console', 'signageforge:self-update', '--yes', '--no-interaction', '--env='.$this->environment];

        if ('\\' === \DIRECTORY_SEPARATOR) {
            $line = 'start "" /B '.implode(' ', array_map('escapeshellarg', $command)).' > '.escapeshellarg($log).' 2>&1';
        } else {
            $line = 'nohup '.implode(' ', array_map('escapeshellarg', $command)).' > '.escapeshellarg($log).' 2>&1 &';
        }

        // The shell returns at once; the update keeps running on its own.
        Process::fromShellCommandline($line, $this->projectDir, null, null, 10)->run();
    }
}
