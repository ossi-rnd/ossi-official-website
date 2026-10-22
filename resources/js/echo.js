import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * WebSocket connection to Reverb for live notifications. Without the VITE_REVERB_*
 * settings (or with the Reverb server down) nothing connects and the bell simply
 * refreshes on page loads, as before.
 */
export default function startEcho() {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key || window.Echo) return window.Echo ?? null;

    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return window.Echo;
}
