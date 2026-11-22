import { Controller } from '@hotwired/stimulus';

/* Follows a running update and reloads the page when it is over. */
export default class extends Controller {
    static targets = ['title', 'log'];
    static values = { url: String, state: String };

    connect() {
        if (this.stateValue === 'running') {
            this.timer = setInterval(() => this.refresh(), 2000);
        }
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async refresh() {
        try {
            const status = await (await fetch(this.urlValue, { headers: { Accept: 'application/json' } })).json();
            this.logTarget.replaceChildren(...status.log.map((line) => {
                const item = document.createElement('li');
                item.textContent = line;
                return item;
            }));
            if (status.state !== 'running') {
                clearInterval(this.timer);
                setTimeout(() => window.location.reload(), 1500);
            }
        } catch {
            // The site may restart during the update: keep trying.
        }
    }
}
