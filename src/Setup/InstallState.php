<?php

declare(strict_types=1);

namespace App\Setup;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Is Signage Forge set up? Yes once an account exists. The answer is kept in
 * a flag file so ordinary requests never pay for the check, and an install
 * done from the command line is detected on its first web request.
 */
final class InstallState
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%signageforge.installed_flag%')] private readonly string $flagFile,
    ) {
    }

    public function isInstalled(): bool
    {
        if (is_file($this->flagFile)) {
            return true;
        }

        try {
            $users = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user');
        } catch (\Throwable) {
            return false; // no database or no schema yet
        }

        if ($users > 0) {
            $this->markInstalled();

            return true;
        }

        return false;
    }

    public function markInstalled(): void
    {
        if (!is_dir(\dirname($this->flagFile))) {
            @mkdir(\dirname($this->flagFile), 0775, true);
        }
        @file_put_contents($this->flagFile, date(\DATE_ATOM));
    }
}
