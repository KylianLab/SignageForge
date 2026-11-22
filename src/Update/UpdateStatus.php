<?php

declare(strict_types=1);

namespace App\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Progress of a running update, in a file, so the admin page can follow an
 * update that runs in a separate process.
 */
final class UpdateStatus
{
    public const IDLE = 'idle';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public function __construct(
        #[Autowire('%signageforge.update_status_file%')] private readonly string $file,
    ) {
    }

    /** @return array{state: string, version: string, log: list<string>, updatedAt: string} */
    public function read(): array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
        $data = \is_array($data) ? $data : [];

        return [
            'state' => \is_string($data['state'] ?? null) ? $data['state'] : self::IDLE,
            'version' => \is_string($data['version'] ?? null) ? $data['version'] : '',
            'log' => array_values(array_filter(\is_array($data['log'] ?? null) ? $data['log'] : [], 'is_string')),
            'updatedAt' => \is_string($data['updatedAt'] ?? null) ? $data['updatedAt'] : '',
        ];
    }

    public function isRunning(): bool
    {
        $status = $this->read();

        // A run that stopped reporting for 30 minutes is considered dead.
        return self::RUNNING === $status['state']
            && '' !== $status['updatedAt']
            && strtotime($status['updatedAt']) > time() - 1800;
    }

    public function start(string $version, string $line): void
    {
        $this->write(['state' => self::RUNNING, 'version' => $version, 'log' => [$line]]);
    }

    public function log(string $line): void
    {
        $status = $this->read();
        $status['log'][] = $line;
        $this->write($status);
    }

    public function finish(bool $success, string $line): void
    {
        $status = $this->read();
        $status['log'][] = $line;
        $status['state'] = $success ? self::DONE : self::FAILED;
        $this->write($status);
    }

    /** @param array{state: string, version: string, log: list<string>, updatedAt?: string} $status */
    private function write(array $status): void
    {
        $status['updatedAt'] = date(\DATE_ATOM);
        if (!is_dir(\dirname($this->file))) {
            @mkdir(\dirname($this->file), 0775, true);
        }
        file_put_contents($this->file, json_encode($status, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
    }
}
