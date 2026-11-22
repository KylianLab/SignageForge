<?php

declare(strict_types=1);

namespace App\Setup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Proves that whoever runs the web installer has access to the server: a
 * random token written to a file only the server can read. Without it, the
 * first visitor of a fresh install could take it over.
 */
final class SetupToken
{
    public function __construct(
        #[Autowire('%signageforge.setup_token_file%')] private readonly string $file,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function file(): string
    {
        return $this->file;
    }

    /** Creates the token on first use. */
    public function ensure(): void
    {
        if (is_file($this->file)) {
            return;
        }
        if (!is_dir(\dirname($this->file))) {
            @mkdir(\dirname($this->file), 0775, true);
        }
        file_put_contents($this->file, bin2hex(random_bytes(12))."\n");
        @chmod($this->file, 0600);
        $this->logger->warning('Signage Forge setup token written to {file}. Open /install and enter it.', ['file' => $this->file]);
    }

    public function isValid(string $candidate): bool
    {
        $token = is_file($this->file) ? trim((string) file_get_contents($this->file)) : '';

        return '' !== $token && hash_equals($token, trim($candidate));
    }

    public function remove(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
