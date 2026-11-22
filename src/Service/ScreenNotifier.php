<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Playlist;
use App\Entity\Scheduled;
use App\Entity\ScreenGroup;
use App\Entity\Screen;
use App\Repository\ScreenRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells players that something changed. With Mercure the hub pushes a private
 * update to the screen's own topic; in polling mode there is nothing to do, the
 * player notices the change on its next check (ETag mismatch).
 */
final class ScreenNotifier
{
    public const EVENT_PLAYLIST_CHANGED = 'playlist_changed';
    public const EVENT_SCREEN_UPDATED = 'screen_updated';
    public const EVENT_RELOAD = 'reload';

    private readonly RealtimeMode $mode;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly ScreenRepository $screens,
        #[Autowire('%signageforge.realtime%')] string $mode,
    ) {
        $this->mode = RealtimeMode::tryFrom($mode) ?? RealtimeMode::Polling;
    }

    public function getMode(): RealtimeMode
    {
        return $this->mode;
    }

    public static function topicFor(string $deviceToken): string
    {
        return 'signageforge/screens/'.$deviceToken;
    }

    public function notifyDevice(string $deviceToken, string $event): void
    {
        if (RealtimeMode::Mercure !== $this->mode) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                self::topicFor($deviceToken),
                json_encode(['type' => $event], \JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $e) {
            // A hub outage must never break the dashboard: players still
            // refresh on their periodic heartbeat.
            $this->logger->warning('Could not publish screen update to Mercure: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    public function notifyScreen(Screen $screen, string $event): void
    {
        $this->notifyDevice($screen->getDeviceToken(), $event);
    }

    /**
     * Device tokens of every screen of an organization (read before deleting
     * something they might play).
     *
     * @return list<string>
     */
    public function deviceTokensOf(Organization $organization): array
    {
        if (RealtimeMode::Mercure !== $this->mode) {
            return []; // polling screens find out on their own
        }

        return array_map(
            static fn (Screen $screen): string => $screen->getDeviceToken(),
            $this->screens->findBy(['organization' => $organization]),
        );
    }

    /** @param iterable<string> $deviceTokens */
    public function notifyDevices(iterable $deviceTokens, string $event = self::EVENT_PLAYLIST_CHANGED): void
    {
        foreach ($deviceTokens as $deviceToken) {
            $this->notifyDevice($deviceToken, $event);
        }
    }

    /** Every screen that may play this playlist, directly or through its group. */
    public function notifyPlaylist(Playlist $playlist): void
    {
        foreach ($playlist->getScreens() as $screen) {
            $this->notifyScreen($screen, self::EVENT_PLAYLIST_CHANGED);
        }
        foreach ($playlist->getGroups() as $group) {
            $this->notifyScheduled($group);
        }
    }

    /** A screen, or every screen of a group, after its playlist or schedule changed. */
    public function notifyScheduled(Scheduled $owner): void
    {
        $screens = $owner instanceof ScreenGroup ? $owner->getScreens() : [$owner];
        foreach ($screens as $screen) {
            if ($screen instanceof Screen) {
                $this->notifyScreen($screen, self::EVENT_PLAYLIST_CHANGED);
            }
        }
    }
}
