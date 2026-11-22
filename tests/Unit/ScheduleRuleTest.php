<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Organization;
use App\Entity\Playlist;
use App\Entity\ScheduleRule;
use App\Entity\Screen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleRuleTest extends TestCase
{
    private const WEEKDAYS = 31; // Mon-Fri
    private const MONDAY = 1;

    /** @return iterable<string, array{int, string, string, string, bool}> */
    public static function cases(): iterable
    {
        // 2026-11-23 is a Monday.
        yield 'weekday morning, inside' => [self::WEEKDAYS, '08:00', '12:00', '2026-11-23 09:30', true];
        yield 'end is exclusive' => [self::WEEKDAYS, '08:00', '12:00', '2026-11-23 12:00', false];
        yield 'start is inclusive' => [self::WEEKDAYS, '08:00', '12:00', '2026-11-23 08:00', true];
        yield 'weekend day' => [self::WEEKDAYS, '08:00', '12:00', '2026-11-22 09:30', false];
        yield 'overnight, evening of the day' => [self::MONDAY, '22:00', '06:00', '2026-11-23 23:00', true];
        yield 'overnight, morning after' => [self::MONDAY, '22:00', '06:00', '2026-11-24 05:59', true];
        yield 'overnight, morning of the day itself' => [self::MONDAY, '22:00', '06:00', '2026-11-23 05:00', false];
        yield 'overnight from Sunday into Monday' => [64, '22:00', '06:00', '2026-11-23 01:00', true];
        yield 'all day' => [self::MONDAY, '00:00', '00:00', '2026-11-23 23:59', true];
        yield 'all day, other day' => [self::MONDAY, '00:00', '00:00', '2026-11-24 00:00', false];
    }

    #[DataProvider('cases')]
    public function testIsActiveAt(int $days, string $start, string $end, string $at, bool $expected): void
    {
        [$screen, $playlist] = $this->screenAndPlaylist();
        $rule = new ScheduleRule($screen, $playlist, $days, (int) ScheduleRule::parseTime($start), (int) ScheduleRule::parseTime($end));

        self::assertSame($expected, $rule->isActiveAt(new \DateTimeImmutable($at)));
    }

    public function testFirstMatchingRuleWinsAndTheDefaultFillsTheRest(): void
    {
        [$screen, $default] = $this->screenAndPlaylist();
        $screen->setPlaylist($default);
        $morning = new Playlist($screen->getOrganization(), 'Morning');
        $special = new Playlist($screen->getOrganization(), 'Special');
        $screen->addScheduleRule($morning, self::WEEKDAYS, 8 * 60, 12 * 60);
        $screen->addScheduleRule($special, self::MONDAY, 9 * 60, 10 * 60);

        self::assertSame($morning, $screen->playlistAt(new \DateTimeImmutable('2026-11-23 09:30')));
        self::assertSame($default, $screen->playlistAt(new \DateTimeImmutable('2026-11-23 13:00')));
    }

    public function testTimesAreValidated(): void
    {
        self::assertSame(510, ScheduleRule::parseTime('08:30'));
        self::assertSame(510, ScheduleRule::parseTime('8:30'));
        self::assertNull(ScheduleRule::parseTime('24:00'));
        self::assertNull(ScheduleRule::parseTime('noon'));

        [$screen, $playlist] = $this->screenAndPlaylist();
        $this->expectException(\InvalidArgumentException::class);
        new ScheduleRule($screen, $playlist, 0, 0, 60);
    }

    /** @return array{Screen, Playlist} */
    private function screenAndPlaylist(): array
    {
        $organization = new Organization('Org', 'org');

        return [new Screen($organization, 'Lobby', 'device-token-schedule-1'), new Playlist($organization, 'Default')];
    }
}
