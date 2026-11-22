<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs bin/console commands in a child process. Used by setup and upgrade:
 * the child boots a fresh kernel, so it sees new settings, new code and a
 * new schema that the current process cannot.
 */
final class ConsoleRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /**
     * @param list<string>                $arguments
     * @param array<string, string|false> $env       variables to set, or false to unset
     * @param (callable(string): void)|null $output  receives everything the command prints
     */
    public function run(array $arguments, array $env = [], ?callable $output = null, bool $tty = false): bool
    {
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        // Same environment as the caller unless told otherwise (the installer
        // unsets APP_ENV so the child reads the .env.local it just wrote).
        $env = array_merge(['APP_ENV' => $this->environment], $env);
        if (false === $env['APP_ENV'] && !\array_key_exists('APP_DEBUG', $env)) {
            // The child picks its own environment: let it pick debug too,
            // instead of inheriting this process's.
            $env['APP_DEBUG'] = false;
        }
        $process = new Process([$php, $this->projectDir.'/bin/console', ...$arguments], $this->projectDir, $env, timeout: null);
        if ($tty && Process::isTtySupported()) {
            $process->setTty(true);
        }
        $process->run(static function (string $type, string $buffer) use ($output): void {
            if (null !== $output) {
                $output($buffer);
            }
        });

        return $process->isSuccessful();
    }
}
