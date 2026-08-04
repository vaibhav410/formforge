import Sortable from 'sortablejs';
import qrcode from 'qrcode-generator';

/** Render a share-link QR into the given container element. */
window.renderShareQr = function (el, url) {
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    const svg = el.querySelector('svg');
    if (svg) {
        svg.setAttribute('class', 'w-44 h-44');
        svg.removeAttribute('width');
        svg.removeAttribute('height');
    }
};

/**
 * Alpine component for the builder frame: client-side undo/redo history.
 * The server dispatches `schema-committed` after each successful persist;
 * we keep snapshots here and push one back via $wire.applySnapshot().
 */
window.builderShell = function () {
    return {
        undoStack: [],
        redoStack: [],
        applying: false,
        canUndo: false,
        canRedo: false,

        pushHistory(schema) {
            if (this.applying) {
                this.applying = false;
                return;
            }
            this.undoStack.push(JSON.stringify(schema));
            if (this.undoStack.length > 50) this.undoStack.shift();
            this.redoStack = [];
            this.sync();
        },

        undo() {
            // The top of the undo stack is the *current* state.
            if (this.undoStack.length < 2) return;
            this.redoStack.push(this.undoStack.pop());
            this.applying = true;
            this.$wire.applySnapshot(JSON.parse(this.undoStack[this.undoStack.length - 1]));
            this.sync();
        },

        redo() {
            if (!this.redoStack.length) return;
            const snapshot = this.redoStack.pop();
            this.undoStack.push(snapshot);
            this.applying = true;
            this.$wire.applySnapshot(JSON.parse(snapshot));
            this.sync();
        },

        sync() {
            this.canUndo = this.undoStack.length > 1;
            this.canRedo = this.redoStack.length > 0;
        },

        init() {
            // Seed history with the initial schema.
            this.pushHistory(this.$wire.schema);
            this.initSortables();

            // Livewire morphs the DOM after every update; re-attach
            // Sortable to any new field containers.
            Livewire.hook('morph.updated', () => {
                queueMicrotask(() => this.initSortables());
            });
        },

        initSortables() {
            const component = this.$root;

            // Palette → canvas (clone out, never reorder the palette).
            const palette = component.querySelector('#palette');
            if (palette && !palette._sortable) {
                palette._sortable = Sortable.create(palette, {
                    group: { name: 'fields', pull: 'clone', put: false },
                    sort: false,
                    animation: 150,
                });
            }

            // Field lists (per section), cross-section drags allowed.
            component.querySelectorAll('.field-container').forEach((el) => {
                if (el._sortable) return;
                el._sortable = Sortable.create(el, {
                    group: 'fields',
                    handle: '.drag-handle',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    onAdd: (evt) => {
                        // A palette item dropped into the canvas.
                        const type = evt.item.dataset.fieldType;
                        if (!type) return;
                        const sectionId = evt.to.dataset.sectionId;
                        const index = evt.newIndex;
                        evt.item.remove(); // Livewire re-renders the real card
                        this.$wire.addField(type, sectionId, index);
                    },
                    onEnd: (evt) => {
                        if (evt.item.dataset.fieldType) return; // handled by onAdd
                        const fieldId = evt.item.dataset.fieldId;
                        const toSectionId = evt.to.dataset.sectionId;
                        if (!fieldId || !toSectionId) return;
                        this.$wire.moveField(fieldId, toSectionId, evt.newIndex);
                    },
                });
            });

            // Section reordering on the canvas column.
            const canvas = component.querySelector('main > div');
            if (canvas && !canvas._sortable) {
                canvas._sortable = Sortable.create(canvas, {
                    handle: '.section-drag-handle',
                    animation: 150,
                    onEnd: (evt) => {
                        const sectionEl = evt.item.querySelector('.field-container');
                        if (!sectionEl) return;
                        this.$wire.moveSection(sectionEl.dataset.sectionId, evt.newIndex);
                    },
                });
            }
        },
    };
};
