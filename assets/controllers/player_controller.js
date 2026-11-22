import { Controller } from '@hotwired/stimulus';

const HEARTBEAT_MS = 60_000;

/*
 * The fullscreen player. Loads its playlist from the config endpoint, loops
 * through images, videos and web pages, and keeps playing from cache when the
 * network drops (config in localStorage, media in the service worker cache).
 *
 * Updates arrive either from a Mercure hub (hubUrl set) or by polling the
 * config endpoint with an ETag, so an unchanged playlist costs a 304.
 */
export default class extends Controller {
    static targets = ['message', 'stage', 'offline'];
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
        if (this.hasHubUrlValue && this.hubUrlValue) {
            this.listen();
            this.poller = setInterval(() => this.load(), HEARTBEAT_MS);
        } else {
            this.poller = setInterval(() => this.load(), Math.max(5000, this.pollIntervalValue));
        }
    }

    disconnect() {
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
        const items = config.playlist?.items ?? [];
        const signature = JSON.stringify(items);
        if (signature === this.signature) return; // same content: don't interrupt playback
        this.signature = signature;

        if (!config.paired) {
            // The screen was removed from the dashboard: forget the token so
            // /pair starts a fresh pairing.
            localStorage.removeItem('signageforge.deviceToken');
            this.showMessage('<p class="mb-2 text-lg font-semibold">This screen isn’t paired</p><p class="text-gray-400">Open <span class="font-mono">/pair</span> on this device to set it up.</p>');
            return;
        }
        if (!items.length) {
            this.showMessage(`<p class="text-lg font-semibold">${escapeHtml(config.screen?.name ?? 'Screen')} is ready</p><p class="text-gray-400">Assign a playlist from the dashboard to start playing.</p>`);
            return;
        }

        this.messageTarget.hidden = true;
        this.index = 0;
        this.play();
        this.preload(items);
    }

    play() {
        clearTimeout(this.advanceTimer);
        const items = this.config?.playlist?.items ?? [];
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
        const urls = items.filter((item) => item.type !== 'URL').map((item) => item.url);
        navigator.serviceWorker.ready
            .then((registration) => registration.active?.postMessage({ type: 'keep', urls }))
            .catch(() => {});
    }

    next() {
        const count = this.config?.playlist?.items?.length ?? 0;
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
