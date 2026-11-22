<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ConsoleRunner;
use App\Setup\DatabaseSettings;
use App\Setup\EnvFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guided setup: database, real-time mode, secrets, schema and first admin.
 * Writes .env.local; every question also has an option for scripted installs.
 */
#[AsCommand(name: 'signageforge:install', description: 'Configure Signage Forge (database, real-time, first admin) and create the schema')]
final class InstallCommand
{
    private const ENGINES = ['sqlite', 'mysql', 'postgresql'];

    public function __construct(
        private readonly EnvFile $envFile,
        private readonly ConsoleRunner $runner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Database engine: sqlite, mysql (MariaDB/MySQL) or postgresql')] ?string $database = null,
        #[Option('Full DATABASE_URL (skips the database questions)')] ?string $databaseUrl = null,
        #[Option('Public URL of this Signage Forge, e.g. https://signage.example.org')] ?string $publicUrl = null,
        #[Option('Real-time mode: polling or mercure')] ?string $realtime = null,
        #[Option('Public URL of the Mercure hub (mercure mode)')] ?string $mercureUrl = null,
        #[Option('Environment: prod or dev')] ?string $appEnv = null,
        #[Option('Email of the first admin')] ?string $adminEmail = null,
        #[Option('Password of the first admin')] ?string $adminPassword = null,
        #[Option('Skip creating an admin')] bool $noAdmin = false,
    ): int {
        $io->title('Signage Forge setup');
        $values = [];

        // --- Environment ---------------------------------------------------
        $appEnv ??= $io->choice('Environment', ['prod', 'dev'], 'prod');
        if (!\in_array($appEnv, ['prod', 'dev'], true)) {
            $io->error('--app-env must be "prod" or "dev".');

            return Command::INVALID;
        }
        $values['APP_ENV'] = $appEnv;
        $values['APP_SECRET'] = bin2hex(random_bytes(16));

        $publicUrl ??= $io->ask('Public URL of Signage Forge', 'http://localhost:8000');
        $publicUrl = rtrim((string) $publicUrl, '/');
        $values['DEFAULT_URI'] = $publicUrl;

        // --- Database ------------------------------------------------------
        if (null === $databaseUrl) {
            $database ??= $io->choice('Database', self::ENGINES, 'sqlite');
            if (!\in_array($database, self::ENGINES, true)) {
                $io->error('--database must be one of: '.implode(', ', self::ENGINES));

                return Command::INVALID;
            }
            $databaseUrl = $this->askDatabaseUrl($io, $database);
        }
        $values['DATABASE_URL'] = $databaseUrl;

        // --- Real-time -----------------------------------------------------
        $io->text([
            'Screens can learn about changes in two ways:',
            ' <info>polling</info>  they check every few seconds. Nothing else to install.',
            ' <info>mercure</info>  instant push through a Mercure hub you run next to PHP.',
        ]);
        $realtime ??= $io->choice('Real-time mode', ['polling', 'mercure'], 'polling');
        if (!\in_array($realtime, ['polling', 'mercure'], true)) {
            $io->error('--realtime must be "polling" or "mercure".');

            return Command::INVALID;
        }
        $values['SIGNAGEFORGE_REALTIME'] = $realtime;
        if ('mercure' === $realtime) {
            $mercureUrl ??= $io->ask('Public URL of the Mercure hub', $publicUrl.'/.well-known/mercure');
            $values['MERCURE_PUBLIC_URL'] = (string) $mercureUrl;
            $values['MERCURE_URL'] = (string) $mercureUrl;
            $values['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(32));
            $io->note('Configure your Mercure hub with this key for both publishers and subscribers: MERCURE_JWT_SECRET in .env.local');
        }

        // --- Write .env.local ----------------------------------------------
        $this->envFile->write($values);
        $io->success('Wrote '.$this->envFile->path());

        // --- Schema, assets, admin (in child processes, so they read the new settings)
        $childEnv = array_fill_keys(array_keys($values), false);

        // SQLite creates its file on first use. For servers, try to create the
        // database; it is fine if it already exists or the user may not create one.
        if (!str_starts_with($databaseUrl, 'sqlite')) {
            $this->console($io, ['doctrine:database:create', '--if-not-exists'], $childEnv, quiet: true);
        }
        if (!$this->console($io, ['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'], $childEnv)) {
            $io->error('Could not create the database schema. Check DATABASE_URL in .env.local and run "php bin/console doctrine:migrations:migrate".');

            return Command::FAILURE;
        }

        if ('prod' === $appEnv) {
            if (!$this->console($io, ['tailwind:build', '--minify'], $childEnv)
                || !$this->console($io, ['asset-map:compile'], $childEnv)) {
                $io->warning('Building the front-end assets failed. Run "php bin/console tailwind:build --minify" and "php bin/console asset-map:compile" once the issue is fixed.');
            }
        }

        if (!$noAdmin) {
            $adminEmail ??= $io->ask('Email of the first admin (leave empty to skip)');
            if (\is_string($adminEmail) && '' !== $adminEmail) {
                $command = ['signageforge:user:create', $adminEmail, '--admin'];
                if (null !== $adminPassword) {
                    $command[] = '--password='.$adminPassword;
                }
                $this->console($io, $command, $childEnv, interactive: null === $adminPassword);
            }
        }

        $io->success('Signage Forge is configured.');
        $io->text([
            'Next steps:',
            ' - Point your web server at the <info>public/</info> folder (see docs/deployment.md).',
            ' - Make <info>var/</info> and <info>data/</info> writable by the web server user.',
            ' - Open '.$publicUrl.'/pair on a screen, then '.$publicUrl.'/dashboard to claim it.',
        ]);

        return Command::SUCCESS;
    }

    private function askDatabaseUrl(SymfonyStyle $io, string $engine): string
    {
        if ('sqlite' === $engine) {
            return DatabaseSettings::SQLITE_URL;
        }

        $isMysql = 'mysql' === $engine;
        $settings = new DatabaseSettings(
            engine: $engine,
            host: (string) $io->ask('Database host', '127.0.0.1'),
            port: (string) $io->ask('Database port', $isMysql ? '3306' : '5432'),
            name: (string) $io->ask('Database name', 'signageforge'),
            user: (string) $io->ask('Database user', 'signageforge'),
            password: (string) $io->askHidden('Database password'),
            serverVersion: (string) $io->ask(
                $isMysql ? 'Server version (e.g. 10.11.2-MariaDB or 8.0.36)' : 'Server version (e.g. 16)',
                $isMysql ? '10.11.2-MariaDB' : '16',
            ),
        );

        return $settings->url();
    }

    /**
     * @param list<string>                $arguments
     * @param array<string, string|false> $env
     */
    private function console(SymfonyStyle $io, array $arguments, array $env, bool $quiet = false, bool $interactive = false): bool
    {
        return $this->runner->run($arguments, $env, $quiet ? null : static function (string $buffer) use ($io): void {
            $io->write($buffer);
        }, $interactive);
    }
}
