// Checks that the player's schedule logic (assets/player/schedule.js) picks
// the same playlist as the server for the cases from schedule-cases.php:
//   php tests/Player/schedule-cases.php > var/schedule-cases.json
//   node tests/Player/schedule-parity.mjs var/schedule-cases.json
import { readFileSync } from 'node:fs';
import { activePlaylist } from '../../assets/player/schedule.js';

const cases = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const mismatches = [];

for (const c of cases) {
    const playlists = { default: { id: 'default', name: 'default', items: [] } };
    for (const rule of c.rules) playlists[rule.playlist] = { id: rule.playlist, name: rule.playlist, items: [] };
    const got = activePlaylist({ timezone: c.tz, default: 'default', rules: c.rules, playlists }, new Date(c.instant))?.name ?? null;
    if (got !== c.expected) mismatches.push({ ...c, got });
}

console.log(`${cases.length - mismatches.length}/${cases.length} cases agree`);
if (mismatches.length) {
    console.error(JSON.stringify(mismatches.slice(0, 5), null, 2));
    process.exit(1);
}
