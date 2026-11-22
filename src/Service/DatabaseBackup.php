<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Safety copy before migrations. SQLite is copied as a file. For MariaDB,
 * MySQL and PostgreSQL the server's own backups apply: we say so instead of
 * guessing credentials for dump tools.
 */
final class DatabaseBackup
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/var/backups')] private readonly string $backupDir,
    ) {
    }

    public function supported(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    /** Returns the path of the copy, or null when this database has to be backed up by other means. */
    public function backup(): ?string
    {
        if (!$this->supported()) {
            return null;
        }

        $params = $this->connection->getParams();
        $source = $params['path'] ?? null;
        if (!\is_string($source) || !is_file($source)) {
            return null; // nothing to back up yet (fresh install)
        }

        $target = \sprintf('%s/%s-%s.db', $this->backupDir, pathinfo($source, \PATHINFO_FILENAME), date('Ymd-His'));
        $this->connection->close(); // no open handle while copying
        (new Filesystem())->copy($source, $target, true);

        return $target;
    }
}
