<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PlayerTest extends FunctionalTestCase
{
    public function testPollingModeDoesNotTalkToAHub(): void
    {
        $this->client->request('GET', '/player/some-device-token-1234');

        self::assertSelectorExists('[data-player-poll-interval-value]');
        self::assertSelectorNotExists('[data-player-hub-url-value]');
    }

    public function testMercureModeSubscribesTheScreenToItsOwnPrivateTopic(): void
    {
        $previous = [$_SERVER['SIGNAGEFORGE_REALTIME'] ?? null, $_ENV['SIGNAGEFORGE_REALTIME'] ?? null];
        $_SERVER['SIGNAGEFORGE_REALTIME'] = $_ENV['SIGNAGEFORGE_REALTIME'] = 'mercure';

        try {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $client->request('GET', '/player/some-device-token-1234');

            self::assertResponseIsSuccessful();
            $hubUrl = $client->getCrawler()->filter('[data-controller=player]')->attr('data-player-hub-url-value');
            self::assertNotNull($hubUrl);
            self::assertStringContainsString(rawurlencode('signageforge/screens/some-device-token-1234'), $hubUrl);
            // The subscriber JWT travels in a cookie scoped to the hub.
            self::assertNotNull($client->getResponse()->headers->getCookies()[0] ?? null);
        } finally {
            [$_SERVER['SIGNAGEFORGE_REALTIME'], $_ENV['SIGNAGEFORGE_REALTIME']] = $previous;
        }
    }
}
