<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Screen;
use App\Enum\MediaType;
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
        #[Autowire('%env(SIGNAGEFORGE_TIMEZONE)%')] string $timezone = '',
    ) {
        $this->timezone = new \DateTimeZone('' !== $timezone ? $timezone : date_default_timezone_get());
    }

    /**
     * @return array{paired: bool, screen?: array{name: string, orientation: string}, playlist?: array{id: string, name: string, items: list<array{id: string, type: string, url: string, durationMs: int}>}|null}
     */
    public function build(?Screen $screen): array
    {
        if (null === $screen) {
            return ['paired' => false];
        }

        // Schedules use the site's local time, not the server's.
        $playlist = $screen->playlistAt($this->clock->now()->setTimezone($this->timezone));
        $items = [];
        foreach ($playlist?->getItems() ?? [] as $item) {
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

        return [
            'paired' => true,
            'screen' => [
                'name' => $screen->getName(),
                'orientation' => $screen->getOrientation()->value,
            ],
            'playlist' => null === $playlist ? null : [
                'id' => (string) $playlist->getId(),
                'name' => $playlist->getName(),
                'items' => $items,
            ],
        ];
    }
}
