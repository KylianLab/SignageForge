<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every outgoing HTTP request in tests ends here: GitHub releases (release
 * 9.9.9 with its package) and Open-Meteo (Brussels, 7°, light rain).
 */
final class FakeInternet
{
    public function __invoke(string $method, string $url): MockResponse
    {
        $json = static fn (array $data): MockResponse => new MockResponse((string) json_encode($data), ['response_headers' => ['content-type' => 'application/json']]);

        if (str_ends_with($url, '/releases/latest')) {
            return $json([
                'tag_name' => 'v9.9.9',
                'html_url' => 'https://github.com/example/signageforge/releases/tag/v9.9.9',
                'published_at' => '2026-11-20T10:00:00Z',
                'body' => 'Notes',
                'assets' => [
                    ['name' => 'signageforge-9.9.9.zip', 'browser_download_url' => 'https://example.test/signageforge-9.9.9.zip'],
                    ['name' => 'signageforge-9.9.9.zip.sha256', 'browser_download_url' => 'https://example.test/signageforge-9.9.9.zip.sha256'],
                ],
            ]);
        }
        if (str_starts_with($url, 'https://geocoding-api.open-meteo.com/')) {
            return $json(str_contains($url, 'name=Brussels') ? ['results' => [
                ['name' => 'Brussels', 'country' => 'Belgium', 'country_code' => 'BE', 'latitude' => 50.85045, 'longitude' => 4.34878],
            ]] : []);
        }
        if (str_starts_with($url, 'https://api.open-meteo.com/')) {
            return $json(['current' => ['temperature_2m' => 7.4, 'weather_code' => 61, 'is_day' => 1]]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
