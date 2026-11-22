<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Answers the update checker in tests: release 9.9.9 with its package. */
final class FakeGitHub
{
    public function __invoke(string $method, string $url): MockResponse
    {
        if (str_ends_with($url, '/releases/latest')) {
            return new MockResponse((string) json_encode([
                'tag_name' => 'v9.9.9',
                'html_url' => 'https://github.com/example/signageforge/releases/tag/v9.9.9',
                'published_at' => '2026-11-20T10:00:00Z',
                'body' => 'Notes',
                'assets' => [
                    ['name' => 'signageforge-9.9.9.zip', 'browser_download_url' => 'https://example.test/signageforge-9.9.9.zip'],
                    ['name' => 'signageforge-9.9.9.zip.sha256', 'browser_download_url' => 'https://example.test/signageforge-9.9.9.zip.sha256'],
                ],
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
