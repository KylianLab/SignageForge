import { Controller } from '@hotwired/stimulus';

const TOKEN_KEY = 'signageforge.deviceToken';

/*
 * /pair on a display device: get a code, show it, poll until an admin claims
 * it from the dashboard, then go to the player. A device that was paired
 * before goes straight to its player.
 */
export default class extends Controller {
    static targets = ['loading', 'error', 'ready', 'code'];
    static values = { startUrl: String, statusUrl: String, playerUrl: String };

    connect() {
        const existing = localStorage.getItem(TOKEN_KEY);
        if (existing) {
            this.goToPlayer(existing);
            return;
        }
        this.start();
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async start() {
        try {
            const res = await fetch(this.startUrlValue, { method: 'POST', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Could not reach the server');
            const { code, deviceToken } = await res.json();
            localStorage.setItem(TOKEN_KEY, deviceToken);
            this.codeTarget.textContent = code;
            this.show('ready');
            this.timer = setInterval(() => this.poll(deviceToken), 3000);
        } catch (e) {
            this.errorTarget.textContent = e instanceof Error ? e.message : 'Something went wrong';
            this.show('error');
            // Retry: the server may just be starting up.
            this.timer = setTimeout(() => window.location.reload(), 15000);
        }
    }

    async poll(deviceToken) {
        try {
            const url = `${this.statusUrlValue}?deviceToken=${encodeURIComponent(deviceToken)}`;
            const data = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
            if (data.status === 'claimed') {
                this.goToPlayer(deviceToken);
            } else if (data.status === 'expired' || data.status === 'unknown') {
                localStorage.removeItem(TOKEN_KEY);
                window.location.reload();
            }
        } catch {
            // Offline for a moment: keep trying.
        }
    }

    goToPlayer(token) {
        window.location.replace(this.playerUrlValue.replace('DEVICE-TOKEN-PLACEHOLDER', encodeURIComponent(token)));
    }

    show(name) {
        for (const target of ['loading', 'error', 'ready']) {
            this[`${target}Target`].classList.toggle('hidden', target !== name);
        }
    }
}
