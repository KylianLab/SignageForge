import { Controller } from '@hotwired/stimulus';

/*
 * Reloads a <turbo-frame> periodically (e.g. to refresh screen online status),
 * but never while the user is interacting with it.
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 15000 } };

    connect() {
        this.timer = setInterval(() => this.refresh(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    refresh() {
        if (document.hidden || this.element.contains(document.activeElement)) return;
        this.element.src = null;
        this.element.src = this.urlValue;
    }
}
