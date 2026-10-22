/**
 * A styled replacement for the native <select> list (the OS draws <option>,
 * so Tailwind can't). The real <select> stays in the DOM, hidden, as the
 * single source of truth: wire:model keeps working, and we write back to it
 * with a real "change" event.
 *
 * searchable: a filter box at the top of the list (long lists, like the 83
 * provinces). It matches anywhere in the label, so "pam" and "PAM" both find
 * "Pampanga · PAM". Typing on the closed button opens it with that letter.
 */
export default function customSelect(searchable = false) {
    return {
        open: false,
        dropUp: false,
        options: [],
        selectedValue: '',
        highlightedIndex: -1,
        searchable,
        query: '',

        /** The options the list draws: all of them, or those matching the search. */
        get shown() {
            const query = this.query.trim().toLowerCase();

            if (query === '') return this.options;

            // The empty "Select…" choice isn't a match for anything typed. Best first: the
            // value itself ("pam" → PAM), then labels starting with it, then the rest.
            const rank = (option) => (option.value.toLowerCase() === query ? 0 : option.label.toLowerCase().startsWith(query) ? 1 : 2);

            return this.options
                .filter((option) => option.value !== '' && (option.label.toLowerCase().includes(query) || option.value.toLowerCase() === query))
                .map((option, index) => ({ option, index }))
                .sort((a, b) => rank(a.option) - rank(b.option) || a.index - b.index)
                .map(({ option }) => option);
        },

        init() {
            this.sync();
            // wire:model sets the <select>'s value while Alpine initializes its
            // children, which is after this init(); a value set that way fires
            // no event, so read it again once they're done.
            this.$nextTick(() => this.sync());
            this.observer = new MutationObserver(() => this.sync());
            this.observer.observe(this.$refs.select, { childList: true, subtree: true, attributes: true });
            this.$refs.select.addEventListener('change', () => this.sync());

            // The server changing the value (e.g. a card number filling in the province)
            // sets the <select>'s value with no event and no mutation: watch the property.
            const model = Array.from(this.$refs.select.attributes).find((attribute) => attribute.name.startsWith('wire:model'));
            if (model && this.$wire && /^[\w.]+$/.test(model.value)) {
                this.$watch('$wire.' + model.value, () => setTimeout(() => this.sync()));
            }
        },

        destroy() {
            this.observer?.disconnect();
        },

        sync() {
            // A closing modal can fire a last mutation after its <select> is already gone.
            if (!this.$refs.select) {
                return;
            }

            this.options = Array.from(this.$refs.select.options).map((option) => ({
                value: option.value,
                label: option.textContent.trim(),
                disabled: option.disabled,
            }));
            this.selectedValue = this.$refs.select.value;
        },

        get selectedLabel() {
            return this.options.find((option) => option.value === this.selectedValue)?.label ?? '';
        },

        toggle() {
            this.open ? this.close() : this.openList();
        },

        openList(query = '') {
            this.dropUp = this.shouldDropUp();
            this.query = query;
            this.open = true;
            this.highlightedIndex = query === '' ? Math.max(0, this.options.findIndex((option) => option.value === this.selectedValue)) : 0;

            if (this.searchable) {
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },

        /** Typing in the search box: highlight the first match. */
        onSearch() {
            this.highlightedIndex = this.shown.length > 0 ? 0 : -1;
        },

        /**
         * Open upward when the list (max-h-60, 240px) doesn't fit below the
         * button but fits better above, inside whatever clips it: a modal's
         * scrolling body or the window.
         */
        shouldDropUp() {
            const button = this.$refs.button.getBoundingClientRect();
            let clip = { top: 0, bottom: window.innerHeight };

            for (let node = this.$el.parentElement; node; node = node.parentElement) {
                if (/(auto|scroll|hidden)/.test(getComputedStyle(node).overflowY)) {
                    const box = node.getBoundingClientRect();
                    clip = { top: Math.max(0, box.top), bottom: Math.min(window.innerHeight, box.bottom) };
                    break;
                }
            }

            const below = clip.bottom - button.bottom;
            const above = button.top - clip.top;

            return below < 248 && above > below;
        },

        close() {
            this.open = false;
            this.highlightedIndex = -1;
            this.query = '';
        },

        choose(option) {
            if (option.disabled) return;

            if (this.$refs.select.value !== option.value) {
                this.$refs.select.value = option.value;
                this.$refs.select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.close();
            this.$refs.button.focus();
        },

        onKeydown(event) {
            if (!this.open && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                this.openList();
                return;
            }

            // A letter on the closed button of a searchable list starts the search with it.
            if (!this.open && this.searchable && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                this.openList(event.key);
                return;
            }

            if (!this.open) return;

            // In the search box a space is part of the search ("del norte"), not a choice.
            const inSearch = event.target === this.$refs.search;

            // Letters typed fast, before the search box has focus, still go into the search.
            if (this.searchable && !inSearch && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                this.query += event.key;
                this.onSearch();
                this.$refs.search?.focus();
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.highlightedIndex = Math.min(this.highlightedIndex + 1, this.shown.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.highlightedIndex = Math.max(this.highlightedIndex - 1, 0);
            } else if (event.key === 'Enter' || (event.key === ' ' && !inSearch)) {
                event.preventDefault();
                const option = this.shown[this.highlightedIndex];
                if (option) this.choose(option);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
                this.$refs.button.focus();
            } else if (event.key === 'Tab') {
                this.close();
            }
        },
    };
}
