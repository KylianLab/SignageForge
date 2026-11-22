import { Controller } from '@hotwired/stimulus';
import { activePlaylist, allItems } from '../player/schedule.js';

const HEARTBEAT_MS = 60_000;
/* How often the screen checks its schedule against its own clock. */
const SCHEDULE_CHECK_MS = 15_000;

/*
 * The fullscreen player. Loads its playlist from the config endpoint, loops
 * through images, videos and web pages, and keeps playing from cache when the
 * network drops (config in localStorage, media in the service worker cache).
 *
 * Updates arrive either from a Mercure hub (hubUrl set) or by polling the
 * config endpoint with an ETag, so an unchanged playlist costs a 304.
 */
export default class extends Controller {
    static targets = ['message', 'stage', 'offline', 'overlays'];
    static values = {
        configUrl: String,
        cacheKey: String,
        serviceWorker: String,
        pollInterval: { type: Number, default: 15000 },
        hubUrl: String,
    };

    connect() {
        this.config = null;
        this.etag = null;
        this.index = 0;
        this.signature = null;

        if ('serviceWorker' in navigator && this.serviceWorkerValue) {
            navigator.serviceWorker.register(this.serviceWorkerValue).catch(() => {});
        }

        this.load();
        this.scheduleTimer = setInterval(() => this.choosePlaylist(), SCHEDULE_CHECK_MS);
        if (this.hasHubUrlValue && this.hubUrlValue) {
            this.listen();
            this.poller = setInterval(() => this.load(), HEARTBEAT_MS);
        } else {
            this.poller = setInterval(() => this.load(), Math.max(5000, this.pollIntervalValue));
        }
    }

    disconnect() {
        clearInterval(this.scheduleTimer);
        clearInterval(this.clockTimer);
        clearInterval(this.poller);
        clearTimeout(this.advanceTimer);
        this.events?.close();
    }

    listen() {
        this.events = new EventSource(this.hubUrlValue, { withCredentials: true });
        this.events.onmessage = () => this.load();
        this.events.onopen = () => this.setOnline(true);
        // EventSource reconnects on its own; the heartbeat poll covers the gap.
    }

    async load() {
        try {
            const headers = { Accept: 'application/json' };
            if (this.etag) headers['If-None-Match'] = this.etag;
            const res = await fetch(this.configUrlValue, { cache: 'no-store', headers });
            this.setOnline(true);
            if (res.status === 304) return;
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            this.etag = res.headers.get('ETag');
            const config = await res.json();
            if (config.paired) localStorage.setItem(this.cacheKeyValue, JSON.stringify(config));
            this.apply(config);
        } catch {
            // Network down: keep playing what we have, or the last cached copy.
            this.setOnline(false);
            if (!this.config) {
                const cached = localStorage.getItem(this.cacheKeyValue);
                if (cached) {
                    this.apply(JSON.parse(cached));
                } else {
                    // Never played anything yet: wait for the network, but keep
                    // the pairing (an outage must not unpair the screen).
                    this.showMessage('<p class="text-lg font-semibold">Waiting for the network…</p>');
                }
            }
        }
    }

    apply(config) {
        this.config = config;
        this.rotate(config.screen?.rotation ?? 0);
        this.renderOverlays(config.paired ? config.overlays : null);

        if (!config.paired) {
            if (this.signature === 'unpaired') return;
            this.signature = 'unpaired';
            // The screen was removed from the dashboard: forget the token so
            // /pair starts a fresh pairing.
            localStorage.removeItem('signageforge.deviceToken');
            this.showMessage('<p class="mb-2 text-lg font-semibold">This screen isn’t paired</p><p class="text-gray-400">Open <span class="font-mono">/pair</span> on this device to set it up.</p>');
            return;
        }

        this.preload(config.schedule ? allItems(config.schedule) : (config.playlist?.items ?? []));
        this.choosePlaylist();
    }

    /*
     * Plays what the schedule says for now, using this screen's own clock, so
     * playlists change on time even without network. Older configurations
     * without a schedule use the server's choice.
     */
    choosePlaylist() {
        const config = this.config;
        if (!config?.paired) return;
        const playlist = config.schedule ? activePlaylist(config.schedule) : config.playlist;
        const items = playlist?.items ?? [];
        const signature = JSON.stringify(items);
        if (signature === this.signature) return; // same content: don't interrupt playback
        this.signature = signature;
        this.items = items;

        if (!items.length) {
            this.showMessage(`<p class="text-lg font-semibold">${escapeHtml(config.screen?.name ?? 'Screen')} is ready</p><p class="text-gray-400">Assign a playlist from the dashboard to start playing.</p>`);
            return;
        }

        this.messageTarget.hidden = true;
        this.index = 0;
        this.play();
    }

    play() {
        clearTimeout(this.advanceTimer);
        const items = this.items ?? [];
        if (!items.length) return;
        if (this.index >= items.length) this.index = 0;
        const item = items[this.index];

        let element;
        if (item.type === 'VIDEO') {
            element = document.createElement('video');
            Object.assign(element, { src: item.url, autoplay: true, muted: true, playsInline: true });
            element.onended = () => this.next();
            element.onerror = () => this.next();
            // A single-video playlist should loop rather than reload.
            if (items.length === 1) element.loop = true;
        } else if (item.type === 'URL') {
            element = document.createElement('iframe');
            element.src = item.url;
            element.className = 'border-0';
            element.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups');
            element.setAttribute('referrerpolicy', 'no-referrer');
        } else {
            element = document.createElement('img');
            element.src = item.url;
            element.alt = '';
            element.onerror = () => { this.advanceTimer = setTimeout(() => this.next(), 2000); };
        }
        element.classList.add('h-full', 'w-full', 'object-contain');
        this.stageTarget.replaceChildren(element);

        if (item.type !== 'VIDEO' && items.length > 1) {
            this.advanceTimer = setTimeout(() => this.next(), item.durationMs || 8000);
        }
    }

    /* Ask the service worker to cache every file of the playlist now, so an
       outage does not hit items that have not been played yet. */
    preload(items) {
        if (!('serviceWorker' in navigator)) return;
        const urls = [...new Set(items.filter((item) => item.type !== 'URL').map((item) => item.url))];
        const signature = urls.join('|');
        if (signature === this.preloadSignature) return; // the service worker already has this list
        this.preloadSignature = signature;
        navigator.serviceWorker.ready
            .then((registration) => registration.active?.postMessage({ type: 'keep', urls }))
            .catch(() => {});
    }

    /* Clock, weather and ticker over the content; redrawn only when they change. */
    renderOverlays(overlays) {
        const signature = JSON.stringify(overlays ?? null);
        if (signature === this.overlaySignature) return;
        this.overlaySignature = signature;
        clearInterval(this.clockTimer);

        const layer = this.overlaysTarget;
        layer.replaceChildren();
        layer.classList.toggle('has-ticker', Boolean(overlays?.ticker));
        if (!overlays) return;

        if (overlays.clock) {
            const box = corner(overlays.clock.position, 'overlay-clock');
            const text = document.createElement('div');
            const time = div('overlay-clock-time');
            const date = div('overlay-clock-date');
            text.append(time);
            if (overlays.clock.showDate) text.append(date);
            box.append(text);
            layer.append(box);

            const tick = () => {
                const now = new Date();
                const zone = { timeZone: overlays.timezone };
                time.textContent = now.toLocaleTimeString(undefined, { ...zone, hour: '2-digit', minute: '2-digit' });
                date.textContent = now.toLocaleDateString(undefined, { ...zone, weekday: 'long', day: 'numeric', month: 'long' });
            };
            tick();
            this.clockTimer = setInterval(tick, 1000);
        }

        if (overlays.weather) {
            const w = overlays.weather;
            const box = corner(w.position, 'overlay-weather');
            box.innerHTML = weatherIcon(w.condition, w.isDay);
            const text = document.createElement('div');
            const temp = div('overlay-weather-temp');
            temp.textContent = `${w.temperature}°`;
            const label = div('overlay-weather-label');
            label.textContent = w.label;
            text.append(temp, label);
            box.append(text);
            layer.append(box);
        }

        if (overlays.ticker) {
            const bar = div('overlay-ticker');
            const text = div('overlay-ticker-text');
            text.textContent = overlays.ticker.messages.join('   •   ');
            bar.append(text);
            layer.append(bar);
            // Constant reading speed: a long message takes longer to cross.
            requestAnimationFrame(() => {
                const screens = text.scrollWidth / Math.max(1, bar.clientWidth);
                text.style.animationDuration = `${Math.max(5, screens * overlays.ticker.secondsPerScreen)}s`;
            });
        }
    }

    /* Turns the content for displays mounted on their side. */
    rotate(degrees) {
        for (const angle of [90, 180, 270]) {
            this.element.classList.toggle(`rotate-${angle}`, angle === degrees);
        }
    }

    next() {
        const count = this.items?.length ?? 0;
        this.index = count ? (this.index + 1) % count : 0;
        this.play();
    }

    showMessage(html) {
        clearTimeout(this.advanceTimer);
        this.stageTarget.replaceChildren();
        this.messageTarget.innerHTML = `<div class="text-center">${html}</div>`;
        this.messageTarget.hidden = false;
    }

    setOnline(online) {
        this.offlineTarget.classList.toggle('hidden', online);
    }
}

function div(className) {
    const element = document.createElement('div');
    element.className = className;
    return element;
}

function corner(position, className) {
    return div(`overlay-corner ${position} ${className}`);
}

/* A few simple pictures for the sky; no icon font or CDN on the screen. */
function weatherIcon(condition, isDay) {
    const sun = '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>';
    const moon = '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>';
    const cloud = '<path d="M17.5 19H9a7 7 0 1 1 6.7-9h1.8a4.5 4.5 0 1 1 0 9Z"/>';
    const shapes = {
        clear: isDay ? sun : moon,
        'partly-cloudy': '<path d="M12 2v2M4.9 4.9l1.4 1.4M20 12h2M19.1 4.9l-1.4 1.4"/><path d="M15.9 13A4 4 0 0 0 8.1 10"/><path d="M13 22H7a5 5 0 1 1 4.9-6H13a3 3 0 0 1 0 6Z"/>',
        cloudy: cloud,
        fog: '<path d="M4 14h16M4 18h16M6 10h12"/>',
        rain: cloud.replace('19H9', '15H9').replace('a4.5 4.5 0 1 1 0 9Z', 'a4.5 4.5 0 1 1 0 5Z') + '<path d="M8 19v2M12 19v2M16 19v2"/>',
        snow: cloud.replace('19H9', '15H9').replace('a4.5 4.5 0 1 1 0 9Z', 'a4.5 4.5 0 1 1 0 5Z') + '<path d="M8 19h.01M12 20h.01M16 19h.01"/>',
        storm: cloud.replace('19H9', '15H9').replace('a4.5 4.5 0 1 1 0 9Z', 'a4.5 4.5 0 1 1 0 5Z') + '<path d="m13 16-3 4h4l-3 4"/>',
    };
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${shapes[condition] ?? cloud}</svg>`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
