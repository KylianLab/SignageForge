<?php

declare(strict_types=1);

namespace App\Update;

/** A published Signage Forge release, as found on GitHub. */
final readonly class Release
{
    public function __construct(
        public string $version,
        public string $pageUrl,
        public ?\DateTimeImmutable $publishedAt,
        public string $notes,
        /** The ready-to-run package (dependencies and assets included), if attached. */
        public ?string $packageUrl,
        /** SHA-256 of the package, published next to it. */
        public ?string $checksumUrl,
    ) {
    }

    public function isNewerThan(string $version): bool
    {
        return version_compare($this->version, $version, '>');
    }

    public function hasPackage(): bool
    {
        return null !== $this->packageUrl && null !== $this->checksumUrl;
    }
}
