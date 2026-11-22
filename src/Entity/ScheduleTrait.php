<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\Collection;

/**
 * Schedule behaviour shared by screens and groups. The using class maps
 * $scheduleRules and $playlist itself (the Doctrine mapping differs).
 *
 * @property Collection<int, ScheduleRule> $scheduleRules
 */
trait ScheduleTrait
{
    /** @return Collection<int, ScheduleRule> */
    public function getScheduleRules(): Collection
    {
        return $this->scheduleRules;
    }

    public function addScheduleRule(Playlist $playlist, int $days, int $startMinute, int $endMinute): ScheduleRule
    {
        $position = 0;
        foreach ($this->scheduleRules as $rule) {
            $position = max($position, $rule->getPosition() + 1);
        }
        $rule = new ScheduleRule($this, $playlist, $days, $startMinute, $endMinute, $position);
        $this->scheduleRules->add($rule);

        return $rule;
    }

    public function removeScheduleRule(ScheduleRule $rule): void
    {
        $this->scheduleRules->removeElement($rule);
    }

    /** The playlist of this schedule now: the first matching rule, else the default playlist. */
    protected function scheduledPlaylistAt(\DateTimeImmutable $localTime): ?Playlist
    {
        foreach ($this->scheduleRules as $rule) {
            if ($rule->isActiveAt($localTime)) {
                return $rule->getPlaylist();
            }
        }

        return $this->getPlaylist();
    }
}
