// jsQR (~130 KB) is only the fallback for browsers without the built-in BarcodeDetector,
// so it's loaded the first time one of those reads a frame, not with every page.
let jsQRModule = null;

/**
 * Checkpoint scanner. Opens the BACK camera, reads QR codes from the live
 * video (the browser's BarcodeDetector where it exists, jsQR elsewhere,
 * e.g. iPhone Safari), and on a hit sends the code, that same camera frame
 * (proof of the spot) and the GPS fix to ClockPanel::submitScan().
 *
 * Lives only while the modal is open (x-if); destroy() stops camera and GPS.
 */
const MAX_SIDE = 960;

export default function patrolScanner({ maxAccuracy = 100 } = {}) {
    return {
        stream: null,
        watchId: null,
        coords: null,
        detector: null,
        timer: null,
        busy: false,
        error: null,
        locationError: null,
        message: null,
        ok: false,
        failed: false,
        pauseUntil: 0,

        async init() {
            this.startLocation();
            await this.startCamera();

            if ('BarcodeDetector' in window) {
                try {
                    this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                } catch {
                    this.detector = null;
                }
            }

            this.timer = setInterval(() => this.scanFrame(), 350);
        },

        destroy() {
            clearInterval(this.timer);
            this.stream?.getTracks().forEach((track) => track.stop());
            if (this.watchId !== null) navigator.geolocation?.clearWatch(this.watchId);
        },

        async startCamera() {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                this.error = 'The camera needs HTTPS. Open this site with https://';
                return;
            }

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
            } catch (error) {
                this.error = `Camera unavailable: ${error.message}`;
            }
        },

        startLocation() {
            if (!navigator.geolocation) {
                this.error = 'This browser cannot share its location.';
                return;
            }

            this.watchId = navigator.geolocation.watchPosition(
                (position) => { this.coords = position.coords; this.locationError = null; },
                // Passing errors (position unavailable, a timeout) clear on the next reading.
                (error) => { this.locationError = error.code === error.PERMISSION_DENIED ? 'Location is blocked. Allow it for this site to scan.' : 'Looking for your GPS position…'; },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 },
            );
        },

        frameCanvas() {
            const video = this.$refs.video;
            if (!video || !video.videoWidth) return null;

            const scale = Math.min(1, MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

            return canvas;
        },

        async readCode(canvas) {
            if (this.detector) {
                const found = await this.detector.detect(canvas).catch(() => []);
                return found[0]?.rawValue ?? null;
            }

            const { default: jsQR } = await (jsQRModule ??= import('jsqr'));
            const ctx = canvas.getContext('2d');
            const image = ctx.getImageData(0, 0, canvas.width, canvas.height);

            return jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })?.data ?? null;
        },

        async scanFrame() {
            // After a refusal, wait before reading again: the sticker is usually still in view,
            // and re-sending it twice a second would only repeat the same answer.
            if (this.busy || this.ok || Date.now() < this.pauseUntil) return;

            const canvas = this.frameCanvas();
            if (!canvas) return;

            const code = await this.readCode(canvas);
            if (!code) return;

            if (!this.coords) {
                this.message = 'QR read. Waiting for your GPS position…';
                return;
            }

            if ((this.coords.accuracy ?? 0) > maxAccuracy) {
                this.message = `QR read. Getting a precise GPS fix (now ±${Math.round(this.coords.accuracy)} m)… keep the camera on the sticker.`;
                return;
            }

            this.busy = true;
            this.failed = false;
            this.message = 'Saving the scan…';

            let result;
            try {
                result = await this.$wire.submitScan(
                    code,
                    canvas.toDataURL('image/jpeg', 0.8),
                    this.coords.latitude,
                    this.coords.longitude,
                    this.coords.accuracy ? Math.round(this.coords.accuracy) : null,
                );
            } catch {
                // No answer (no signal, server error): never leave it stuck on "Saving…".
                result = null;
            }

            this.busy = false;

            if (!result || typeof result.ok !== 'boolean') {
                this.fail('The scan didn\'t reach the office (no signal or a server problem). Keep the sticker in view: it tries again in a few seconds.');
                return;
            }

            this.ok = result.ok;

            if (result.ok) {
                this.message = result.message;
                setTimeout(() => this.$dispatch('close-scan-modal'), 1400);
            } else {
                this.fail(result.message);
            }
        },

        fail(message) {
            this.failed = true;
            this.message = message;
            this.pauseUntil = Date.now() + 4000;

            // Let them read it, then go back to looking for a QR.
            setTimeout(() => {
                if (!this.ok && Date.now() >= this.pauseUntil) {
                    this.failed = false;
                    this.message = null;
                }
            }, 4500);
        },
    };
}

/**
 * The best GPS fix within a few seconds, for "Leaving post" and "Back at post".
 *
 * The first reading is often rough (±100 m and worse, from Wi-Fi or cell
 * towers) and the phone sharpens it within seconds, so this watches the
 * position and resolves as soon as it's within `maxAccuracy`, or with the
 * best one seen when time runs out (the server then explains if it's too
 * rough). `onProgress(accuracy)` reports the best so far, for the button.
 *
 * @returns {Promise<{latitude: number, longitude: number, accuracy: number|null}>}
 */
export function currentPosition(maxAccuracy = 100, onProgress = () => {}, timeoutMs = 20000) {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('This browser cannot share its location.'));
            return;
        }

        let best = null;
        let done = false;
        let watchId = null;
        let timer = null;

        const finish = (callback) => {
            if (done) return;
            done = true;
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            clearTimeout(timer);
            callback();
        };

        watchId = navigator.geolocation.watchPosition(
            (position) => {
                const fix = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy ? Math.round(position.coords.accuracy) : null };

                if (best === null || (fix.accuracy ?? Infinity) < (best.accuracy ?? Infinity)) best = fix;
                onProgress(best.accuracy);

                if ((best.accuracy ?? 0) <= maxAccuracy) finish(() => resolve(best));
            },
            // Only "permission denied" ends the wait. Phones report passing errors
            // (position unavailable, a timeout) between readings; keep waiting for a better one.
            (error) => {
                if (error.code === error.PERMISSION_DENIED) finish(() => reject(error));
            },
            { enableHighAccuracy: true, maximumAge: 0 },
        );

        timer = setTimeout(() => finish(() => (best ? resolve(best) : reject(new Error('No GPS position yet. Step outside and try again.')))), timeoutMs);
    });
}
