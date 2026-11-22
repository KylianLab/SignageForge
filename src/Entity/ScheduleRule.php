<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * "On these days, between these times, play this playlist" for one screen or
 * one group of screens. Outside every rule the default playlist plays. When several
 * rules match, the first one in the list wins. A range whose end is before
 * its start runs overnight (22:00-06:00); equal start and end mean all day.
 */
#[ORM\Entity]
#[ORM\Table(name: 'schedule_rule')]
class ScheduleRule
{
    /** Bit per ISO weekday: Monday = 1, Tuesday = 2 ... Sunday = 64. */
    public const ALL_DAYS = 127;
    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    /** Set for a screen's own schedule. */
    #[ORM\ManyToOne(inversedBy: 'scheduleRules')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Screen $screen = null;

    /** Set for a group's schedule. */
    #[ORM\ManyToOne(inversedBy: 'scheduleRules')]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'CASCADE')]
    private ?ScreenGroup $group = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Playlist $playlist;

    #[ORM\Column]
    private int $days;

    /** Minutes since midnight. */
    #[ORM\Column]
    private int $startMinute;

    /** Minutes since midnight, exclusive. */
    #[ORM\Column]
    private int $endMinute;

    #[ORM\Column]
    private int $position;

    public function __construct(Scheduled $owner, Playlist $playlist, int $days, int $startMinute, int $endMinute, int $position = 0)
    {
        if ($days < 1 || $days > self::ALL_DAYS) {
            throw new \InvalidArgumentException('Pick at least one day.');
        }
        foreach ([$startMinute, $endMinute] as $minute) {
            if ($minute < 0 || $minute >= 24 * 60) {
                throw new \InvalidArgumentException('Times must be between 00:00 and 23:59.');
            }
        }

        $this->id = new Ulid();
        if ($owner instanceof Screen) {
            $this->screen = $owner;
        } elseif ($owner instanceof ScreenGroup) {
            $this->group = $owner;
        } else {
            throw new \InvalidArgumentException('A schedule belongs to a screen or a group.');
        }
        $this->playlist = $playlist;
        $this->days = $days;
        $this->startMinute = $startMinute;
        $this->endMinute = $endMinute;
        $this->position = $position;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getOwner(): Scheduled
    {
        return $this->screen ?? $this->group ?? throw new \LogicException('Schedule rule without owner.');
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getDays(): int
    {
        return $this->days;
    }

    public function hasDay(int $isoDay): bool
    {
        return 0 !== ($this->days & (1 << ($isoDay - 1)));
    }

    public function getStartMinute(): int
    {
        return $this->startMinute;
    }

    public function getEndMinute(): int
    {
        return $this->endMinute;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isActiveAt(\DateTimeImmutable $localTime): bool
    {
        $day = (int) $localTime->format('N');
        $minute = (int) $localTime->format('G') * 60 + (int) $localTime->format('i');

        if ($this->startMinute === $this->endMinute) {
            return $this->hasDay($day); // all day
        }
        if ($this->startMinute < $this->endMinute) {
            return $this->hasDay($day) && $minute >= $this->startMinute && $minute < $this->endMinute;
        }

        // Overnight: the evening part belongs to the selected day, the
        // morning part to the day after it.
        $previousDay = 1 === $day ? 7 : $day - 1;

        return ($this->hasDay($day) && $minute >= $this->startMinute)
            || ($this->hasDay($previousDay) && $minute < $this->endMinute);
    }

    public static function formatMinute(int $minute): string
    {
        return \sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /** "08:30" to 510, or null when not a valid time. */
    public static function parseTime(string $time): ?int
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public function describeDays(): string
    {
        if (self::ALL_DAYS === $this->days) {
            return 'Every day';
        }
        if (31 === $this->days) {
            return 'Weekdays';
        }
        if (96 === $this->days) {
            return 'Weekends';
        }

        return implode(', ', array_values(array_filter(
            self::DAY_NAMES,
            fn (string $name, int $day): bool => $this->hasDay($day),
            \ARRAY_FILTER_USE_BOTH,
        )));
    }

    public function describeTimes(): string
    {
        if ($this->startMinute === $this->endMinute) {
            return 'all day';
        }

        return self::formatMinute($this->startMinute).'–'.self::formatMinute($this->endMinute)
            .($this->endMinute < $this->startMinute ? ' (overnight)' : '');
    }
}
