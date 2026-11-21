<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'playlist_item')]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Playlist $playlist;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Media $media;

    #[ORM\Column]
    private int $position;

    /** Overrides the media's own duration for this slot; 0 means "use the media's". */
    #[ORM\Column]
    private int $durationMs;

    public function __construct(Playlist $playlist, Media $media, int $position, int $durationMs = 0)
    {
        $this->id = new Ulid();
        $this->playlist = $playlist;
        $this->media = $media;
        $this->position = $position;
        $this->durationMs = $durationMs;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getEffectiveDurationMs(): int
    {
        return $this->durationMs > 0 ? $this->durationMs : $this->media->getDurationMs();
    }
}
