/**
 * Browser half of the idle timeout. App\Http\Middleware\EnforceIdleTimeout is
 * the enforcement; this file is UX and clock syncing:
 *
 *  - shows a countdown before the deadline instead of dropping the user mid-form;
 *  - shares "last activity" across tabs via localStorage;
 *  - pings the server only after REAL input, so an abandoned tab never keeps a
 *    session alive.
 */
const STORAGE_KEY = 'ossi:last-activity';
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll'];

export default function idleTimeout({ timeoutSeconds, warningSeconds, keepAliveUrl, loginUrl }) {
    return {
        warningVisible: false,
        secondsLeft: warningSeconds,
        lastActivity: Date.now(),
        activitySincePing: false,
        signingOut: false,
        tickHandle: null,
        pingHandle: null,

        init() {
            this.restore();
            this.markActive();

            const onActivity = () => this.markActive();
            ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, onActivity, { passive: true, capture: true }));

            window.addEventListener('storage', (event) => {
                const stamp = Number(event.newValue);
                if (event.key === STORAGE_KEY && Number.isFinite(stamp) && stamp > this.lastActivity) {
                    this.lastActivity = stamp;
                    this.warningVisible = false;
                }
            });

            // A Livewire round trip already resets the server clock.
            window.Livewire?.hook?.('request', () => {
                this.lastActivity = Date.now();
                this.activitySincePing = false;
                this.publish();
            });

            // 419 = the server already ended the session: go to login instead of Livewire's "page expired" dialog.
            window.Livewire?.interceptRequest?.(({ onError }) => {
                onError(({ response, preventDefault }) => {
                    if (response?.status === 419) {
                        preventDefault();
                        window.location.href = loginUrl;
                    }
                });
            });

            this.tickHandle = setInterval(() => this.tick(), 1000);
            this.pingHandle = setInterval(() => this.ping(), Math.max(60, Math.floor(timeoutSeconds / 3)) * 1000);
        },

        destroy() {
            clearInterval(this.tickHandle);
            clearInterval(this.pingHandle);
        },

        get countdownLabel() {
            const minutes = Math.floor(this.secondsLeft / 60);
            const seconds = this.secondsLeft % 60;

            return minutes > 0 ? `${minutes}:${String(seconds).padStart(2, '0')}` : `${seconds}s`;
        },

        markActive() {
            if (this.warningVisible) return; // Only the button dismisses the warning.

            this.lastActivity = Date.now();
            this.activitySincePing = true;
            this.publish();
        },

        publish() {
            try {
                localStorage.setItem(STORAGE_KEY, String(this.lastActivity));
            } catch {
                // Private mode: the timer still works, just not across tabs.
            }
        },

        restore() {
            try {
                const stamp = Number(localStorage.getItem(STORAGE_KEY));
                if (Number.isFinite(stamp) && stamp > 0) this.lastActivity = stamp;
            } catch {
                // Ignore.
            }
        },

        tick() {
            const remaining = timeoutSeconds - Math.floor((Date.now() - this.lastActivity) / 1000);

            if (remaining <= 0) {
                this.signOut();
                return;
            }

            this.warningVisible = remaining <= warningSeconds;
            this.secondsLeft = remaining;
        },

        ping() {
            if (!this.activitySincePing || this.warningVisible || this.signingOut) return;

            this.activitySincePing = false;
            this.keepAlive();
        },

        staySignedIn() {
            this.warningVisible = false;
            this.lastActivity = Date.now();
            this.activitySincePing = false;
            this.publish();
            this.keepAlive();
        },

        keepAlive() {
            fetch(keepAliveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ active: true }),
            }).catch(() => {});
        },

        signOut() {
            if (this.signingOut) return;

            this.signingOut = true;
            this.destroy();
            this.$refs.logoutForm.submit();
        },
    };
}
