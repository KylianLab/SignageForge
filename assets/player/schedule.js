/*
 * Which playlist a schedule plays at a given moment. The same rules as
 * App\Entity\ScheduleRule::isActiveAt() on the server, so a screen without
 * network still switches playlists on time:
 *  - days is a bit mask, Monday = 1, Tuesday = 2 ... Sunday = 64;
 *  - start and end are minutes since midnight, end excluded;
 *  - start == end means all day; end < start runs overnight, the morning
 *    part belonging to the day after the selected one;
 *  - the first matching rule wins, else the default playlist.
 */

const WEEKDAYS = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };

/* ISO weekday (1-7) and minute of the day of `date` in `timeZone`. */
export function localTime(date, timeZone) {
    const parts = {};
    for (const { type, value } of new Intl.DateTimeFormat('en-US', {
        timeZone,
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date)) {
        parts[type] = value;
    }

    return { day: WEEKDAYS[parts.weekday], minute: Number(parts.hour) * 60 + Number(parts.minute) };
}

function hasDay(days, day) {
    return (days & (1 << (day - 1))) !== 0;
}

export function isActive(rule, { day, minute }) {
    if (rule.start === rule.end) {
        return hasDay(rule.days, day);
    }
    if (rule.start < rule.end) {
        return hasDay(rule.days, day) && minute >= rule.start && minute < rule.end;
    }
    const previousDay = day === 1 ? 7 : day - 1;

    return (hasDay(rule.days, day) && minute >= rule.start) || (hasDay(rule.days, previousDay) && minute < rule.end);
}

/* The playlist ({id, name, items}) to play at `date`, or null. */
export function activePlaylist(schedule, date = new Date()) {
    if (!schedule) return null;
    const now = localTime(date, schedule.timezone || 'UTC');
    const rule = (schedule.rules ?? []).find((candidate) => isActive(candidate, now));
    const id = rule ? rule.playlist : schedule.default;

    return id ? schedule.playlists?.[id] ?? null : null;
}

/* Every file any playlist of the schedule may need (to cache them ahead). */
export function allItems(schedule) {
    return Object.values(schedule?.playlists ?? {}).flatMap((playlist) => playlist.items);
}
