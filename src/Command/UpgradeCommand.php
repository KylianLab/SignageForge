<?php

declare(strict_types=1);

namespace App\Command;

use App\Maintenance\MaintenanceMode;
use App\Service\ConsoleRunner;
use App\Service\DatabaseBackup;
use App\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finishes an update once the new code is in place (git pull or a new
 * release, then composer install): maintenance page on, database backup,
 * migrations, front-end assets, cache, maintenance page off.
 */
#[AsCommand(name: 'signageforge:upgrade', description: 'Apply an update: back up the database, migrate, rebuild assets and clear the cache')]
final class UpgradeCommand
{
    public function __construct(
        private readonly MaintenanceMode $maintenance,
        private readonly DatabaseBackup $backup,
        private readonly ConsoleRunner $console,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Skip the database backup')] bool $noBackup = false,
        #[Option('Skip rebuilding the CSS and JavaScript assets')] bool $skipAssets = false,
    ): int {
        $io->title('Signage Forge '.Version::VERSION.': applying the update');
        $print = static function (string $buffer) use ($io): void {
            $io->write($buffer);
        };

        $this->maintenance->enable();
        $io->text('Maintenance page on. Screens keep playing from their cache.');

        try {
            if (!$noBackup) {
                $copy = $this->backup->backup();
                if (null !== $copy) {
                    $io->text('Database backed up to <info>'.$copy.'</info>');
                } elseif (!$this->backup->supported()) {
                    $io->warning('Back up your MariaDB/MySQL/PostgreSQL database with your usual tools before continuing if you have not done so.');
                }
            }

            $io->section('Database');
            if (!$this->console->run(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'], output: $print)) {
                $io->error('The migrations failed. The site stays in maintenance; fix the problem and run this command again.');

                return Command::FAILURE;
            }

            if (!$skipAssets && 'prod' === $this->environment) {
                $io->section('Front-end assets');
                if (!$this->console->run(['tailwind:build', '--minify'], output: $print)
                    || !$this->console->run(['asset-map:compile'], output: $print)) {
                    $io->error('Building the assets failed. The site stays in maintenance; fix the problem and run this command again.');

                    return Command::FAILURE;
                }
            }

            $io->section('Cache');
            if (!$this->console->run(['cache:clear'], output: $print)) {
                $io->error('Clearing the cache failed. The site stays in maintenance; fix the problem and run this command again.');

                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage().' The site stays in maintenance; fix the problem and run this command again.');

            return Command::FAILURE;
        }

        $this->maintenance->disable();
        $io->success('Update applied. Maintenance page off.');

        return Command::SUCCESS;
    }
}
