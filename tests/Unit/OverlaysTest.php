<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Double\FakeInternet;
use App\Widget\Overlays;
use App\Widget\WeatherService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class OverlaysTest extends TestCase
{
    public function testStoredSettingsAreNormalised(): void
    {
        $overlays = Overlays::fromArray([
            'clock' => true,
            'clockPosition' => 'middle', // unknown: falls back
            'ticker' => true,
            'tickerText' => " First \r\n\r\nSecond\n  ",
            'tickerSpeed' => 'warp',
            'latitude' => '50.85',
        ]);

        self::assertTrue($overlays->clock);
        self::assertSame('top-right', $overlays->clockPosition);
        self::assertSame(['First', 'Second'], $overlays->tickerMessages());
        self::assertSame('normal', $overlays->tickerSpeed);
        self::assertSame(50.85, $overlays->latitude);
        self::assertFalse($overlays->hasWeatherPlace());
        self::assertEquals($overlays, Overlays::fromArray($overlays->toArray()));
        self::assertTrue(Overlays::fromArray(null)->isEmpty());
    }

    public function testWeatherPlacesAndConditions(): void
    {
        $weather = new WeatherService(new MockHttpClient(new FakeInternet()), new ArrayAdapter(), new NullLogger(), true);

        self::assertSame(['label' => 'Brussels, Belgium', 'latitude' => 50.8505, 'longitude' => 4.3488], $weather->findPlace('Brussels, BE'));
        self::assertNull($weather->findPlace('Nowhere'));
        self::assertSame(['temperature' => 7, 'condition' => 'rain', 'isDay' => true], $weather->current(50.85, 4.35));

        self::assertSame('clear', WeatherService::condition(0));
        self::assertSame('snow', WeatherService::condition(73));
        self::assertSame('storm', WeatherService::condition(95));
    }

    public function testTheWeatherCanBeTurnedOff(): void
    {
        $weather = new WeatherService(new MockHttpClient(static fn () => throw new \LogicException('No call expected.')), new ArrayAdapter(), new NullLogger(), false);

        self::assertNull($weather->findPlace('Brussels'));
        self::assertNull($weather->current(50.85, 4.35));
    }
}
