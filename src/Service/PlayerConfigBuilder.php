<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Playlist;
use App\Entity\Scheduled;
use App\Entity\Screen;
use App\Enum\MediaType;
use App\Widget\Overlays;
use App\Widget\WeatherService;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Builds what a player needs to know to play its content. */
final class PlayerConfigBuilder
{
    private readonly \DateTimeZone $timezone;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
        private readonly WeatherService $weather,
        #[Autowire('%env(SIGNAGEFORGE_TIMEZONE)%')] string $timezone = '',
    ) {
        $this->timezone = new \DateTimeZone('' !== $timezone ? $timezone : date_default_timezone_get());
    }

    /**
     * What the player draws over the content. Only enabled widgets appear.
     *
     * @return array{timezone: string, clock?: array{position: string, showDate: bool}, weather?: array{position: string, label: string, temperature: int, condition: string, isDay: bool}, ticker?: array{messages: list<string>, secondsPerScreen: int}}
     */
    private function overlays(Overlays $overlays): array
    {
        $result = ['timezone' => $this->timezone->getName()];
        if ($overlays->clock) {
            $result['clock'] = ['position' => $overlays->clockPosition, 'showDate' => $overlays->clockShowDate];
        }
        if ($overlays->weather && null !== $overlays->latitude && null !== $overlays->longitude) {
            $now = $this->weather->current($overlays->latitude, $overlays->longitude);
            if (null !== $now) {
                $result['weather'] = [
                    'position' => $overlays->weatherPosition,
                    'label' => $overlays->weatherLabel,
                    'temperature' => $now['temperature'],
                    'condition' => $now['condition'],
                    'isDay' => $now['isDay'],
                ];
            }
        }
        $messages = $overlays->tickerMessages();
        if ($overlays->ticker && [] !== $messages) {
            $result['ticker'] = ['messages' => $messages, 'secondsPerScreen' => Overlays::SPEEDS[$overlays->tickerSpeed]];
        }

        return $result;
    }

    /**
     * @return array{paired: bool, screen?: array{name: string, orientation: string, rotation: int}, playlist?: array{id: string, name: string, items: list<array{id: string, type: string, url: string, durationMs: int}>}|null, overlays?: array<string, mixed>, schedule?: array<string, mixed>}
     */
    public function build(?Screen $screen): array
    {
        if (null === $screen) {
            return ['paired' => false];
        }

        // Schedules use the site's local time, not the server's.
        $playlist = $screen->playlistAt($this->clock->now()->setTimezone($this->timezone));

        return [
            'paired' => true,
            'screen' => [
                'name' => $screen->getName(),
                'orientation' => $screen->getOrientation()->value,
                'rotation' => $screen->getOrientation()->rotation(),
            ],
            'overlays' => $this->overlays($screen->effectiveOverlays()),
            // What plays right now, as the server sees it.
            'playlist' => null === $playlist ? null : $this->playlist($playlist),
            // The whole schedule, so the screen can switch playlists on time
            // by itself, even without network.
            'schedule' => $this->schedule($screen->getGroup() ?? $screen),
        ];
    }

    /**
     * @return array{timezone: string, default: string|null, rules: list<array{days: int, start: int, end: int, playlist: string}>, playlists: array<string, array{id: string, name: string, items: list<array{id: string, type: string, url: string, durationMs: int}>}>}
     */
    private function schedule(Scheduled $owner): array
    {
        $playlists = [];
        $default = $owner->getPlaylist();
        if (null !== $default) {
            $playlists[(string) $default->getId()] = $this->playlist($default);
        }

        $rules = [];
        foreach ($owner->getScheduleRules() as $rule) {
            $playlist = $rule->getPlaylist();
            $playlists[(string) $playlist->getId()] ??= $this->playlist($playlist);
            $rules[] = [
                'days' => $rule->getDays(),
                'start' => $rule->getStartMinute(),
                'end' => $rule->getEndMinute(),
                'playlist' => (string) $playlist->getId(),
            ];
        }

        return [
            'timezone' => $this->timezone->getName(),
            'default' => null === $default ? null : (string) $default->getId(),
            'rules' => $rules,
            'playlists' => $playlists,
        ];
    }

    /** @return array{id: string, name: string, items: list<array{id: string, type: string, url: string, durationMs: int}>} */
    private function playlist(Playlist $playlist): array
    {
        $items = [];
        foreach ($playlist->getItems() as $item) {
            $media = $item->getMedia();
            $items[] = [
                'id' => (string) $item->getId(),
                'type' => $media->getType()->value,
                // Uploaded bytes come from our file route; URL media is external.
                'url' => MediaType::Url === $media->getType()
                    ? $media->getStorageKey()
                    : $this->urls->generate('app_media_file', ['id' => $media->getId()]),
                'durationMs' => $item->getEffectiveDurationMs(),
            ];
        }

        return ['id' => (string) $playlist->getId(), 'name' => $playlist->getName(), 'items' => $items];
    }
}
