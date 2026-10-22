/**
 * A Livewire request that fails (403, 404, 419, 500, 503…) gets one of our error
 * pages back. Livewire would show it in a pop-up window over the page; instead it
 * replaces the whole page, so every error looks the same as when it comes from a
 * normal page load: full screen, with "Take me home" and "Try again".
 */
export default function registerErrorPages(Livewire) {
    Livewire.interceptRequest(({ onError }) => {
        onError(({ body, preventDefault }) => {
            if (typeof body !== 'string' || body.trim() === '') {
                return; // nothing to show: Livewire's own "refresh the page?" prompt
            }

            preventDefault();
            document.open();
            document.write(body);
            document.close();
        });
    });
}
