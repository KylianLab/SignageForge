<?php

declare(strict_types=1);

namespace App\Setup;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/** Database choice made during setup, turned into a DATABASE_URL. */
final class DatabaseSettings
{
    public const ENGINES = ['sqlite', 'mysql', 'postgresql'];
    public const SQLITE_URL = 'sqlite:///%kernel.project_dir%/data/signageforge.db';

    public function __construct(
        public string $engine = 'sqlite',
        public string $host = '127.0.0.1',
        public string $port = '',
        public string $name = 'signageforge',
        public string $user = 'signageforge',
        public string $password = '',
        public string $serverVersion = '',
    ) {
    }

    public function url(): string
    {
        if ('sqlite' === $this->engine) {
            return self::SQLITE_URL;
        }

        $isMysql = 'mysql' === $this->engine;

        return \sprintf(
            '%s://%s:%s@%s:%s/%s?serverVersion=%s&charset=%s',
            $this->engine,
            rawurlencode($this->user),
            rawurlencode($this->password),
            $this->host,
            '' !== $this->port ? $this->port : ($isMysql ? '3306' : '5432'),
            rawurlencode($this->name),
            rawurlencode('' !== $this->serverVersion ? $this->serverVersion : ($isMysql ? '10.11.2-MariaDB' : '16')),
            $isMysql ? 'utf8mb4' : 'utf8',
        );
    }

    /**
     * Tries to connect. Returns null when it works, the error message otherwise.
     * SQLite needs no server: its folder only has to be writable.
     */
    public static function test(string $url, string $projectDir): ?string
    {
        $url = str_replace('%kernel.project_dir%', $projectDir, $url);
        if (str_starts_with($url, 'sqlite')) {
            $dir = \dirname((string) preg_replace('#^sqlite:///#', '', $url));
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            return is_writable($dir) ? null : \sprintf('The folder %s is not writable by the web server.', $dir);
        }

        try {
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url);
            $connection = DriverManager::getConnection($params);
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            $connection->close();

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
