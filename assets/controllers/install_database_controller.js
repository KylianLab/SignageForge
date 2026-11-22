import { Controller } from '@hotwired/stimulus';

/* Shows the server fields only for MariaDB/MySQL and PostgreSQL. */
export default class extends Controller {
    static targets = ['server'];

    toggle(event) {
        this.serverTarget.classList.toggle('hidden', event.target.value === 'sqlite');
    }
}
