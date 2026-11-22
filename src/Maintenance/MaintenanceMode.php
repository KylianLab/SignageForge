<?php

declare(strict_types=1);

namespace App\Maintenance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * While an update runs, the site answers "back in a moment" instead of
 * half-migrated pages. A file flag, so it survives cache clears and is shared
 * by every PHP worker. Screens keep playing from their offline cache.
 */
final class MaintenanceMode
{
    public function __construct(
        #[Autowire('%signageforge.maintenance_flag%')] private readonly string $flagFile,
    ) {
    }

    public function enable(string $reason = 'Update in progress'): void
    {
        if (!is_dir(\dirname($this->flagFile))) {
            mkdir(\dirname($this->flagFile), 0775, true);
        }
        file_put_contents($this->flagFile, $reason);
    }

    public function disable(): void
    {
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    public function isEnabled(): bool
    {
        return is_file($this->flagFile);
    }
}
