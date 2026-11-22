<?php

declare(strict_types=1);

namespace App\Command;

use App\Maintenance\MaintenanceMode;
use App\Service\ConsoleRunner;
use App\Service\DatabaseBackup;
use App\Update\PackageInstaller;
use App\Update\UpdateChecker;
use App\Update\UpdateStatus;
use App\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installs the latest release published on GitHub: download, checksum,
 * maintenance page, database backup, file swap (with rollback), then the
 * new version's own signageforge:upgrade. Also what the admin page runs.
 */
#[AsCommand(name: 'signageforge:self-update', description: 'Download and install the latest Signage Forge release')]
final class SelfUpdateCommand
{
    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly PackageInstaller $installer,
        private readonly UpdateStatus $status,
        private readonly MaintenanceMode $maintenance,
        private readonly DatabaseBackup $backup,
        private readonly ConsoleRunner $console,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option('Only say whether an update is available')] bool $check = false,
        #[Option('Do not ask for confirmation')] bool $yes = false,
        #[Option('Install this downloaded package instead of asking GitHub (servers without internet access)')] ?string $package = null,
        #[Option('SHA-256 of the package (default: read from <package>.sha256)')] ?string $sha256 = null,
    ): int {
        if (null !== $package) {
            return $this->fromFile($io, $input, $package, $sha256, $yes);
        }
        if (!$this->checker->isEnabled()) {
            $io->error('Update checks are off (SIGNAGEFORGE_UPDATE_REPOSITORY is empty). Update manually, see docs/deployment.md.');

            return Command::FAILURE;
        }

        $release = $this->checker->latest(refresh: true);
        if (null === $release) {
            if (null !== $error = $this->checker->lastError()) {
                $io->error('Could not check for updates: '.$error);

                return Command::FAILURE;
            }
            $io->success('No release has been published yet. Signage Forge '.Version::VERSION.' is what you have.');

            return Command::SUCCESS;
        }
        if (!$release->isNewerThan(Version::VERSION)) {
            $io->success(\sprintf('Signage Forge %s is up to date.', Version::VERSION));

            return Command::SUCCESS;
        }

        $io->text(\sprintf('Version <info>%s</info> is available (installed: %s). %s', $release->version, Version::VERSION, $release->pageUrl));
        if ($check) {
            return Command::SUCCESS;
        }
        if (!$release->hasPackage()) {
            $io->error('This release has no ready-to-install package. Update manually, see docs/deployment.md.');

            return Command::FAILURE;
        }
        if ($this->status->isRunning()) {
            $io->error('An update is already running.');

            return Command::FAILURE;
        }
        if (!$yes && $input->isInteractive() && !$io->confirm('Install it now? The site shows a maintenance page for a minute.', true)) {
            return Command::SUCCESS;
        }

        $workDir = $this->projectDir.'/var/update';
        $this->status->start($release->version, \sprintf('Updating from %s to %s', Version::VERSION, $release->version));

        // 1. Download and check, while the site keeps running.
        try {
            $this->step($io, 'Downloading the package…');
            $zip = $this->installer->download($release, $workDir.'/download');
            $this->step($io, 'Checksum verified.');
            $root = $this->installer->extract($zip, $workDir.'/extract');
        } catch (\Throwable $e) {
            return $this->fail($io, $e->getMessage());
        }

        return $this->install($io, $root, $release->version);
    }

    /** Offline update from a package copied to the server. */
    private function fromFile(SymfonyStyle $io, InputInterface $input, string $package, ?string $sha256, bool $yes): int
    {
        if (!is_file($package)) {
            $io->error('No such file: '.$package);

            return Command::FAILURE;
        }
        $sha256 ??= is_file($package.'.sha256') ? substr(trim((string) file_get_contents($package.'.sha256')), 0, 64) : null;
        if (null === $sha256 || !preg_match('/^[0-9a-f]{64}$/i', $sha256)) {
            $io->error('Give the package checksum with --sha256, or put the .sha256 file published with it next to the package.');

            return Command::FAILURE;
        }
        if ($this->status->isRunning()) {
            $io->error('An update is already running.');

            return Command::FAILURE;
        }

        $workDir = $this->projectDir.'/var/update';
        try {
            $this->installer->verify($package, strtolower($sha256));
            $root = $this->installer->extract($package, $workDir.'/extract');
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $version = self::versionIn($root);
        if (!version_compare($version, Version::VERSION, '>')) {
            $io->error(\sprintf('This package is version %s; %s is installed. Nothing to do.', $version, Version::VERSION));

            return Command::FAILURE;
        }
        if (!$yes && $input->isInteractive() && !$io->confirm(\sprintf('Install %s now? The site shows a maintenance page for a minute.', $version), true)) {
            return Command::SUCCESS;
        }

        $this->status->start($version, \sprintf('Updating from %s to %s (package %s)', Version::VERSION, $version, basename($package)));
        $this->step($io, 'Checksum verified.');

        return $this->install($io, $root, $version);
    }

    private function install(SymfonyStyle $io, string $root, string $version): int
    {
        $workDir = $this->projectDir.'/var/update';

        // 2. Maintenance, backup, new files.
        $this->maintenance->enable();
        $this->step($io, 'Maintenance page on. Screens keep playing from their cache.');
        try {
            $copy = $this->backup->backup();
            if (null !== $copy) {
                $this->step($io, 'Database backed up to '.$copy);
            }
            $replaced = $this->installer->swap($root, $this->projectDir, $workDir.'/previous');
            $this->step($io, 'New files in place.');
        } catch (\Throwable $e) {
            $this->maintenance->disable();

            return $this->fail($io, $e->getMessage());
        }

        // 3. The new version finishes its own upgrade (migrations, cache).
        $output = '';
        $ok = $this->console->run(['signageforge:upgrade', '--no-backup', '--skip-assets', '--no-interaction'], output: static function (string $buffer) use (&$output): void {
            $output .= $buffer;
        });
        if (!$ok) {
            $this->step($io, trim(strip_tags($output)));
            $problems = $this->installer->rollback($replaced, $this->projectDir, $workDir.'/previous');
            if ([] !== $problems) {
                return $this->fail($io, 'The new version could not finish its upgrade, and putting the previous files back was incomplete ('.implode('; ', $problems).'). They are in var/update/previous. The site stays in maintenance.');
            }
            $this->console->run(['cache:clear']);

            return $this->fail($io, 'The new version could not finish its upgrade, so the previous files were put back. The site stays in maintenance: check the database (a backup is in var/backups), then run "php bin/console signageforge:upgrade".');
        }

        (new Filesystem())->remove([$workDir.'/download', $workDir.'/extract']);
        $this->status->finish(true, \sprintf('Signage Forge %s is installed. The previous files are kept in var/update/previous until the next update.', $version));
        $io->success(\sprintf('Signage Forge %s is installed.', $version));

        return Command::SUCCESS;
    }

    private function step(SymfonyStyle $io, string $line): void
    {
        $io->text($line);
        $this->status->log($line);
    }

    /** Reads the version a package will install, from its src/Version.php. */
    private static function versionIn(string $root): string
    {
        $source = (string) @file_get_contents($root.'/src/Version.php');

        return preg_match("/VERSION = '([^']+)'/", $source, $m) ? $m[1] : '0';
    }

    private function fail(SymfonyStyle $io, string $message): int
    {
        $this->status->finish(false, $message);
        $io->error($message);

        return Command::FAILURE;
    }
}
