import Alpine from 'alpinejs';

/**
 * Public fill page behaviour:
 *  - conditional logic mirror (server remains the authority)
 *  - funnel beacons: start / field_focus / abandon
 *  - signature pad component
 */
window.publicForm = function ({ logic, eventUrl, csrf }) {
    return {
        values: {},
        started: false,
        submitted: false,
        lastFocusedKey: null,

        init() {
            this.collect();

            this.$root.querySelector('form')?.addEventListener('submit', () => {
                this.submitted = true;
            });

            // Departure without submitting → abandon beacon with the last
            // field the visitor touched (drop-off analytics).
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden' && this.started && !this.submitted && eventUrl) {
                    const payload = new Blob(
                        [JSON.stringify({ event: 'abandon', field_key: this.lastFocusedKey })],
                        { type: 'application/json' }
                    );
                    navigator.sendBeacon(eventUrl, payload);
                }
            });
        },

        onInput() {
            this.collect();
            this.markStarted();
        },

        onFocus(event) {
            const wrapper = event.target.closest('[data-field-key]');
            if (wrapper) this.lastFocusedKey = wrapper.dataset.fieldKey;
            this.markStarted();
            if (this.lastFocusedKey && eventUrl) {
                this.beacon('field_focus', this.lastFocusedKey);
            }
        },

        markStarted() {
            if (this.started || !eventUrl) {
                this.started = true;
                return;
            }
            this.started = true;
            this.beacon('start', null);
        },

        beacon(event, fieldKey) {
            fetch(eventUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ event, field_key: fieldKey }),
                keepalive: true,
            }).catch(() => {});
        },

        collect() {
            const form = this.$root.querySelector('form');
            if (!form) return;
            const values = {};
            new FormData(form).forEach((value, name) => {
                if (name.startsWith('_')) return;
                if (name.endsWith('[]')) {
                    const key = name.slice(0, -2);
                    (values[key] ??= []).push(value);
                } else {
                    values[name] = value;
                }
            });
            // Unchecked radio groups produce no FormData entry at all;
            // normalise so conditions evaluate against '' not undefined.
            this.values = values;
        },

        visible(key) {
            const config = logic[key];
            if (!config) return true;
            if (config.hidden) return false;
            const rules = config.logic;
            if (!rules) return true;

            const results = rules.conditions.map((c) => this.evaluate(c));
            const matched = rules.match === 'any' ? results.some(Boolean) : results.every(Boolean);
            return rules.action === 'show' ? matched : !matched;
        },

        evaluate(condition) {
            const actual = this.values[condition.field];
            const expected = condition.value;

            switch (condition.operator) {
                case 'equals':
                    return Array.isArray(actual)
                        ? actual.map(String).includes(String(expected))
                        : String(actual ?? '') === String(expected ?? '');
                case 'not_equals':
                    return !(Array.isArray(actual)
                        ? actual.map(String).includes(String(expected))
                        : String(actual ?? '') === String(expected ?? ''));
                case 'contains':
                    return Array.isArray(actual)
                        ? actual.map(String).includes(String(expected))
                        : String(actual ?? '').toLowerCase().includes(String(expected ?? '').toLowerCase());
                case 'greater_than':
                    return parseFloat(actual) > parseFloat(expected);
                case 'less_than':
                    return parseFloat(actual) < parseFloat(expected);
                case 'is_empty':
                    return actual == null || actual === '' || (Array.isArray(actual) && !actual.length);
                case 'is_not_empty':
                    return !(actual == null || actual === '' || (Array.isArray(actual) && !actual.length));
                default:
                    return false;
            }
        },
    };
};

window.signaturePad = function () {
    return {
        drawing: false,
        dirty: false,
        ctx: null,

        init() {
            const canvas = this.$refs.canvas;
            // Match the backing store to the CSS size for crisp strokes.
            const scale = window.devicePixelRatio || 1;
            canvas.width = canvas.offsetWidth * scale;
            canvas.height = canvas.offsetHeight * scale;
            this.ctx = canvas.getContext('2d');
            this.ctx.scale(scale, scale);
            this.ctx.lineWidth = 2;
            this.ctx.lineCap = 'round';
            this.ctx.strokeStyle = '#111827';
        },

        point(event) {
            const rect = this.$refs.canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        },

        start(event) {
            this.drawing = true;
            const { x, y } = this.point(event);
            this.ctx.beginPath();
            this.ctx.moveTo(x, y);
        },

        move(event) {
            if (!this.drawing) return;
            const { x, y } = this.point(event);
            this.ctx.lineTo(x, y);
            this.ctx.stroke();
            this.dirty = true;
            this.$refs.value.value = this.$refs.canvas.toDataURL('image/png');
        },

        stop() {
            this.drawing = false;
        },

        clearPad() {
            const canvas = this.$refs.canvas;
            this.ctx.clearRect(0, 0, canvas.width, canvas.height);
            this.dirty = false;
            this.$refs.value.value = '';
        },
    };
};

// Star-rating hover/checked styling without extra markup.
document.addEventListener('change', (event) => {
    if (!event.target.matches('.rating-group input[type=radio]')) return;
    const group = event.target.closest('.rating-group');
    let reached = false;
    // DOM order is reversed (highest star first).
    group.querySelectorAll('.rating-star').forEach((label) => {
        const input = label.querySelector('input');
        if (input.checked) reached = true;
        label.classList.toggle('text-amber-400', reached);
        label.classList.toggle('text-gray-300', !reached);
    });
});

window.Alpine = Alpine;
Alpine.start();
