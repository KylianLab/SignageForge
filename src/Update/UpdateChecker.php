<?php

declare(strict_types=1);

namespace App\Update;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks GitHub for the latest release, at most every few hours. The only data
 * sent is the request itself; set SIGNAGEFORGE_UPDATE_REPOSITORY to an empty
 * value to never contact GitHub (servers without internet access).
 */
final class UpdateChecker
{
    private const CACHE_KEY = 'signageforge.latest_release';
    private const CACHE_SECONDS = 6 * 3600;

    private ?string $lastError = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(SIGNAGEFORGE_UPDATE_REPOSITORY)%')] private readonly string $repository,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->repository);
    }

    public function repository(): string
    {
        return trim($this->repository);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function latest(bool $refresh = false): ?Release
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if ($refresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        try {
            $data = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->fetch();
            });
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->warning('Could not check for Signage Forge updates: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        return self::toRelease($data);
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $response = $this->httpClient->request('GET', \sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository()), [
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'SignageForge-update-check'],
            'timeout' => 10,
        ]);
        if (404 === $response->getStatusCode()) {
            return []; // no release published yet
        }

        return $response->toArray();
    }

    /** @param array<string, mixed> $data */
    private static function toRelease(array $data): ?Release
    {
        $tag = $data['tag_name'] ?? null;
        if (!\is_string($tag) || '' === $tag) {
            return null;
        }
        $version = ltrim($tag, 'vV');

        $package = $checksum = null;
        foreach (\is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (!\is_array($asset) || !\is_string($asset['name'] ?? null) || !\is_string($asset['browser_download_url'] ?? null)) {
                continue;
            }
            if ($asset['name'] === "signageforge-{$version}.zip") {
                $package = $asset['browser_download_url'];
            } elseif ($asset['name'] === "signageforge-{$version}.zip.sha256") {
                $checksum = $asset['browser_download_url'];
            }
        }

        $published = \is_string($data['published_at'] ?? null) ? new \DateTimeImmutable($data['published_at']) : null;

        return new Release(
            version: $version,
            pageUrl: \is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
            publishedAt: $published,
            notes: \is_string($data['body'] ?? null) ? $data['body'] : '',
            packageUrl: $package,
            checksumUrl: $checksum,
        );
    }
}
