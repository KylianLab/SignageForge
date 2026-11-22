<?php

declare(strict_types=1);

// Random schedule cases with the answer of the PHP rules (the ScheduleRule entity).
// schedule-parity.mjs checks that assets/player/schedule.js agrees. Run from the project root.
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Entity\{Organization, Playlist, Screen};
mt_srand(42);
$org = new Organization('o', 'o');
$cases = [];
foreach (['UTC', 'Europe/Brussels', 'America/New_York'] as $tz) {
    for ($i = 0; $i < 400; ++$i) {
        $screen = new Screen($org, 's', 'tok'.$i);
        $default = new Playlist($org, 'default');
        $screen->setPlaylist($default);
        $rules = [];
        $playlists = ['default' => $default];
        for ($r = 0; $r < mt_rand(1, 3); ++$r) {
            $p = new Playlist($org, 'p'.$r);
            $playlists['p'.$r] = $p;
            $days = mt_rand(1, 127);
            $start = mt_rand(0, 47) * 30; $end = mt_rand(0, 47) * 30;
            $screen->addScheduleRule($p, $days, $start, $end);
            $rules[] = ['days' => $days, 'start' => $start, 'end' => $end, 'playlist' => 'p'.$r];
        }
        $instant = 1795000000 + mt_rand(0, 14 * 86400);
        $local = (new DateTimeImmutable('@'.$instant))->setTimezone(new DateTimeZone($tz));
        $cases[] = ['tz' => $tz, 'instant' => $instant * 1000, 'rules' => $rules, 'expected' => $screen->playlistAt($local)?->getName()];
    }
}
echo json_encode($cases);
