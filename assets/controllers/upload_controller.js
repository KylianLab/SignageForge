import { Controller } from '@hotwired/stimulus';

/* Uploads as soon as files are chosen, and says so. */
export default class extends Controller {
    static targets = ['label'];

    submit(event) {
        if (!event.target.files?.length) return;
        this.labelTarget.textContent = 'Uploading…';
        this.element.requestSubmit();
    }
}
