<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Screen;
use App\Enum\MediaType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Builds what a player needs to know to play its content. */
final class PlayerConfigBuilder
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * @return array{paired: bool, screen?: array{name: string, orientation: string}, playlist?: array{id: string, name: string, items: list<array{id: string, type: string, url: string, durationMs: int}>}|null}
     */
    public function build(?Screen $screen): array
    {
        if (null === $screen) {
            return ['paired' => false];
        }

        $playlist = $screen->getPlaylist();
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
