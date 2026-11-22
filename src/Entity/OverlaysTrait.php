<?php

declare(strict_types=1);

namespace App\Entity;

use App\Widget\Overlays;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Widgets (clock, weather, ticker) of a screen or a group. */
trait OverlaysTrait
{
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $overlays = null;

    public function getOverlays(): Overlays
    {
        return Overlays::fromArray($this->overlays);
    }

    public function setOverlays(Overlays $overlays): void
    {
        $this->overlays = $overlays->toArray();
    }
}
