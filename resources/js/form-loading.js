/**
 * Loading state for PLAIN forms (login, register, password, two-factor, account
 * settings, logout). Livewire marks its own buttons; this does the same for forms
 * that reload the page:
 *
 *  - the button that submitted gets data-loading + aria-busy, so <x-ui.button>
 *    shows its spinner;
 *  - a second submit while the first is on its way is ignored (no double login,
 *    no two reset emails);
 *  - the button is NOT disabled: a disabled submit button drops its name/value
 *    from the request. The component blocks clicks with pointer-events instead.
 *
 * Skipped: forms Livewire handles (it cancels the event first), a submit cancelled
 * by onsubmit="return confirm(…)" (also cancelled), forms opening another tab,
 * and any form marked data-no-loading (e.g. one that downloads a file and so
 * never leaves the page).
 */
export default function registerFormLoading() {
    // Bubble phase: by now the browser's own validation passed (an invalid form never
    // fires "submit") and the form's own handlers, Livewire's included, have run.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
        if (form.hasAttribute('data-no-loading') || (form.target && form.target !== '_self')) return;

        if (form.dataset.submitting) {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = 'true';

        const button = event.submitter ?? form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
        button?.setAttribute('data-loading', 'true');
        button?.setAttribute('aria-busy', 'true');
    });

    // Back button: the browser may restore this page exactly as it was left, mid-submit.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;

        document.querySelectorAll('form[data-submitting]').forEach((form) => {
            delete form.dataset.submitting;
            form.querySelectorAll('[aria-busy="true"]').forEach((button) => {
                button.removeAttribute('data-loading');
                button.removeAttribute('aria-busy');
            });
        });
    });
}
