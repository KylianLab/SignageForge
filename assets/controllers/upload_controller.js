import { Controller } from '@hotwired/stimulus';

/* Uploads as soon as files are chosen, and says so. */
export default class extends Controller {
    static targets = ['label'];
    static values = { busy: { type: String, default: 'Uploading…' } };

    submit(event) {
        if (!event.target.files?.length) return;
        this.labelTarget.textContent = this.busyValue;
        this.element.requestSubmit();
    }
}
