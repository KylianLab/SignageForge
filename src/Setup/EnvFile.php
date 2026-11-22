<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Writes settings to .env.local, keeping what is already there. */
final class EnvFile
{
    public function __construct(
        #[Autowire('%signageforge.env_local%')] private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isWritable(): bool
    {
        return is_file($this->path) ? is_writable($this->path) : is_writable(\dirname($this->path));
    }

    /** @param array<string, string> $values */
    public function render(array $values): string
    {
        $lines = is_file($this->path)
            ? (file($this->path, \FILE_IGNORE_NEW_LINES) ?: [])
            : ['# Local settings written by the Signage Forge installer. Not committed.'];

        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote($value);
            $replaced = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $replaced = true;
                }
            }
            if (!$replaced) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, string> $values */
    public function write(array $values): void
    {
        $content = $this->render($values);
        if (false === @file_put_contents($this->path, $content)) {
            throw new \RuntimeException(\sprintf('Could not write %s.', $this->path));
        }
        @chmod($this->path, 0600);
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:\/@%-]*$/', $value) ? $value : '"'.addcslashes($value, '"\\$').'"';
    }
}
