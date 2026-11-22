<?php

declare(strict_types=1);

namespace App\Setup;

use App\Service\ConsoleRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies a setup chosen in the web installer: writes .env.local, creates the
 * schema, builds assets in production and creates the first admin. The steps
 * that need the new settings run in child processes, which read them fresh.
 */
final class Installer
{
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly ConsoleRunner $console,
        private readonly InstallState $state,
        private readonly SetupToken $token,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /**
     * @param array<string, string> $settings values for .env.local
     *
     * @return list<string> what was done, for the summary page
     *
     * @throws \RuntimeException when a required step fails
     */
    public function apply(array $settings, string $adminEmail, string $adminName, string $adminPassword): array
    {
        $done = [];
        $settings += ['APP_SECRET' => bin2hex(random_bytes(16))];
        if ('mercure' === ($settings['SIGNAGEFORGE_REALTIME'] ?? null)) {
            $settings += ['MERCURE_JWT_SECRET' => bin2hex(random_bytes(32))];
        }

        $this->envFile->write($settings);
        $done[] = 'Settings saved to '.$this->envFile->path();

        $output = '';
        $collect = static function (string $buffer) use (&$output): void {
            $output .= $buffer;
        };

        if (!str_starts_with($settings['DATABASE_URL'] ?? '', 'sqlite')) {
            $this->console->run(['doctrine:database:create', '--if-not-exists'], output: $collect);
        }
        if (!$this->console->run(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'], output: $collect)) {
            throw new \RuntimeException("Creating the database tables failed:\n".self::tail($output));
        }
        $done[] = 'Database tables created';

        if ('prod' === $this->environment) {
            if ($this->console->run(['tailwind:build', '--minify'], output: $collect)
                && $this->console->run(['asset-map:compile'], output: $collect)) {
                $done[] = 'Front-end assets built';
            } else {
                $done[] = 'Front-end assets NOT built: run "php bin/console tailwind:build --minify" and "php bin/console asset-map:compile" on the server';
            }
        }

        $output = '';
        $created = $this->console->run(
            ['signageforge:user:create', $adminEmail, '--admin', '--name='.$adminName, '--no-interaction'],
            ['SIGNAGEFORGE_NEW_PASSWORD' => $adminPassword],
            $collect,
        );
        if (!$created) {
            throw new \RuntimeException("Creating the admin account failed:\n".self::tail($output));
        }
        $done[] = 'Admin account '.$adminEmail.' created';

        $this->state->markInstalled();
        $this->token->remove();

        return $done;
    }

    private static function tail(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", strip_tags($output))));

        return implode("\n", \array_slice($lines, -6));
    }
}
