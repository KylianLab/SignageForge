<?php

declare(strict_types=1);

namespace App\Widget;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weather from Open-Meteo (free, no key, no account). Only the server calls
 * it, at most every 15 minutes per place, so screens never go to the
 * internet. Off with SIGNAGEFORGE_WEATHER=false.
 */
final class WeatherService
{
    private const CACHE_SECONDS = 900;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:SIGNAGEFORGE_WEATHER)%')] private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Finds a place by name ("Brussels", "Gent, Belgium").
     *
     * @return array{label: string, latitude: float, longitude: float}|null
     */
    public function findPlace(string $query): ?array
    {
        $query = trim($query);
        if (!$this->enabled || '' === $query) {
            return null;
        }
        // "City, Country": search the city, prefer the country given.
        [$city, $country] = array_map('trim', explode(',', $query, 2)) + [1 => ''];

        try {
            $results = $this->httpClient->request('GET', 'https://geocoding-api.open-meteo.com/v1/search', [
                'query' => ['name' => $city, 'count' => 10, 'language' => 'en', 'format' => 'json'],
                'timeout' => 10,
            ])->toArray()['results'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('Weather place lookup failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        $results = \is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
        if ('' !== $country) {
            $match = array_values(array_filter($results, static fn (array $r): bool => \is_string($r['country'] ?? null) && 0 === strcasecmp($r['country'], $country) || \is_string($r['country_code'] ?? null) && 0 === strcasecmp($r['country_code'], $country)));
            $results = [] !== $match ? $match : $results;
        }
        $place = $results[0] ?? null;
        if (null === $place || !is_numeric($place['latitude'] ?? null) || !is_numeric($place['longitude'] ?? null)) {
            return null;
        }

        return [
            'label' => implode(', ', array_filter([\is_string($place['name'] ?? null) ? $place['name'] : $city, \is_string($place['country'] ?? null) ? $place['country'] : null])),
            'latitude' => round((float) $place['latitude'], 4),
            'longitude' => round((float) $place['longitude'], 4),
        ];
    }

    /**
     * Current conditions, or null when unknown (off, offline, error).
     *
     * @return array{temperature: int, condition: string, isDay: bool}|null
     */
    public function current(float $latitude, float $longitude): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $key = \sprintf('signageforge.weather.%s.%s', round($latitude, 2), round($longitude, 2));
        try {
            $data = $this->cache->get(str_replace(['.', '-'], ['_', 'm'], $key), function (ItemInterface $item) use ($latitude, $longitude): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->httpClient->request('GET', 'https://api.open-meteo.com/v1/forecast', [
                    'query' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'current' => 'temperature_2m,weather_code,is_day',
                        'timezone' => 'auto',
                    ],
                    'timeout' => 10,
                ])->toArray()['current'] ?? [];
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Weather lookup failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        if (!is_numeric($data['temperature_2m'] ?? null) || !is_numeric($data['weather_code'] ?? null)) {
            return null;
        }

        return [
            'temperature' => (int) round((float) $data['temperature_2m']),
            'condition' => self::condition((int) $data['weather_code']),
            'isDay' => 0 !== (int) ($data['is_day'] ?? 1),
        ];
    }

    /** Groups WMO weather codes into the few pictures the player draws. */
    public static function condition(int $code): string
    {
        return match (true) {
            0 === $code => 'clear',
            \in_array($code, [1, 2], true) => 'partly-cloudy',
            3 === $code => 'cloudy',
            \in_array($code, [45, 48], true) => 'fog',
            $code >= 51 && $code <= 67, $code >= 80 && $code <= 82 => 'rain',
            $code >= 71 && $code <= 77, \in_array($code, [85, 86], true) => 'snow',
            $code >= 95 => 'storm',
            default => 'cloudy',
        };
    }
}
