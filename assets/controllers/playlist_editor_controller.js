import { Controller } from '@hotwired/stimulus';

/*
 * Edits the playing order in the page; the form posts the whole ordered list
 * on save. Items can be added from the library, removed, moved with the arrow
 * buttons or dragged.
 */
export default class extends Controller {
    static targets = ['list', 'item', 'template', 'empty', 'saveLabel'];

    connect() {
        this.saveText = this.saveLabelTarget.textContent;
        this.renumber();
    }

    add({ params: { media } }) {
        const template = this.templateTargets.find((t) => t.dataset.media === String(media));
        if (!template) return;
        this.listTarget.append(template.content.firstElementChild.cloneNode(true));
        this.changed();
    }

    remove(event) {
        event.currentTarget.closest('li').remove();
        this.changed();
    }

    moveUp(event) {
        const item = event.currentTarget.closest('li');
        item.previousElementSibling?.before(item);
        this.changed();
    }

    moveDown(event) {
        const item = event.currentTarget.closest('li');
        item.nextElementSibling?.after(item);
        this.changed();
    }

    dragStart(event) {
        this.dragged = event.currentTarget;
        event.dataTransfer.effectAllowed = 'move';
        this.dragged.classList.add('opacity-50');
    }

    dragOver(event) {
        if (!this.dragged) return;
        event.preventDefault();
        const target = event.currentTarget;
        if (target === this.dragged) return;
        const rect = target.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;
        after ? target.after(this.dragged) : target.before(this.dragged);
    }

    drop(event) {
        event.preventDefault();
    }

    dragEnd() {
        this.dragged?.classList.remove('opacity-50');
        this.dragged = null;
        this.changed();
    }

    markDirty() {
        this.saveLabelTarget.textContent = this.saveText;
        this.element.dataset.dirty = 'true';
    }

    changed() {
        this.renumber();
        this.markDirty();
    }

    renumber() {
        this.itemTargets.forEach((item, index) => {
            item.querySelector('[data-role="position"]').textContent = index + 1;
        });
        this.emptyTarget.classList.toggle('hidden', this.itemTargets.length > 0);
    }

    /* Give each row's fields their indexed names just before submitting. */
    prepare() {
        this.itemTargets.forEach((item, index) => {
            item.querySelectorAll('[data-field]').forEach((field) => {
                field.name = `items[${index}][${field.dataset.field}]`;
            });
        });
    }
}
