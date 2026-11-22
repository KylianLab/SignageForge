<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Organization;
use App\Entity\Playlist;
use App\Entity\Screen;
use App\Repository\ScreenRepository;
use App\Service\RealtimeMode;
use App\Service\ScreenNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class ScreenNotifierTest extends TestCase
{
    public function testMercureModePublishesAPrivateUpdatePerScreen(): void
    {
        /** @var list<Update> $published */
        $published = [];
        $hub = new MockHub('https://hub.test/.well-known/mercure', new StaticTokenProvider('jwt'), static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'id';
        });
        $notifier = new ScreenNotifier($hub, new NullLogger(), $this->createStub(ScreenRepository::class), 'mercure');

        $organization = new Organization('Org', 'org');
        $playlist = new Playlist($organization, 'Hall');
        foreach (['device-token-aaaaaaa1', 'device-token-aaaaaaa2'] as $token) {
            $screen = new Screen($organization, 'S', $token);
            $screen->setPlaylist($playlist);
            $playlist->getScreens()->add($screen);
        }

        $notifier->notifyPlaylist($playlist);

        self::assertSame(RealtimeMode::Mercure, $notifier->getMode());
        self::assertCount(2, $published);
        self::assertSame(['signageforge/screens/device-token-aaaaaaa1'], $published[0]->getTopics());
        self::assertTrue($published[0]->isPrivate());
        self::assertSame('{"type":"playlist_changed"}', $published[0]->getData());
    }

    public function testPollingModePublishesNothing(): void
    {
        $hub = new MockHub('https://hub.test', new StaticTokenProvider('jwt'), static fn (): string => throw new \LogicException('Should not publish.'));
        $notifier = new ScreenNotifier($hub, new NullLogger(), $this->createStub(ScreenRepository::class), 'polling');

        $notifier->notifyDevice('device-token-aaaaaaa1', ScreenNotifier::EVENT_RELOAD);

        self::assertSame(RealtimeMode::Polling, $notifier->getMode());
    }

    public function testAHubOutageDoesNotBreakTheDashboard(): void
    {
        $hub = new MockHub('https://hub.test', new StaticTokenProvider('jwt'), static fn (): string => throw new RuntimeException('Hub down'));
        $notifier = new ScreenNotifier($hub, new NullLogger(), $this->createStub(ScreenRepository::class), 'mercure');

        $notifier->notifyDevice('device-token-aaaaaaa1', ScreenNotifier::EVENT_RELOAD);

        $this->addToAssertionCount(1);
    }
}
