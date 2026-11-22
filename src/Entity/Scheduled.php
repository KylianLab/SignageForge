<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Ulid;

/** Something with a default playlist and a schedule: a screen or a group of screens. */
interface Scheduled
{
    public function getId(): Ulid;

    public function getName(): string;

    public function getOrganization(): Organization;

    public function getPlaylist(): ?Playlist;

    public function setPlaylist(?Playlist $playlist): void;

    /** @return Collection<int, ScheduleRule> */
    public function getScheduleRules(): Collection;

    public function addScheduleRule(Playlist $playlist, int $days, int $startMinute, int $endMinute): ScheduleRule;

    public function removeScheduleRule(ScheduleRule $rule): void;
}
