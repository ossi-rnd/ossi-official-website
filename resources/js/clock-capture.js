/**
 * Clock in/out camera modal. Lives only while the modal is open (x-if), and
 * destroy() stops the camera and GPS watch, so nothing keeps running after
 * the punch.
 *
 * The photo is drawn onto a canvas with a "timemark" burned in (event, time,
 * address, coordinates), downscaled to fit Livewire's 1 MB request limit, and
 * sent to ClockPanel::submitPunch(), which returns { ok, message }.
 *
 * Capture takes ONE snapshot (position, accuracy and the address looked up for that
 * position) and both the photo's timemark and the saved record use it. The GPS keeps
 * moving while the camera is open, and the address is looked up at most every 15 s,
 * so reading them again at Submit (or burning a stale address) made the photo and
 * the record disagree.
 */
const MAX_SIDE = 960;
const JPEG_QUALITY = 0.82;
// An address looked up farther than this from the current position is out of date.
const ADDRESS_FRESH_METERS = 25;

function metersBetween(a, b) {
    const rad = (deg) => (deg * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLon = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLon / 2) ** 2;

    return 2 * 6371000 * Math.asin(Math.sqrt(h));
}

export default function clockCapture({ punchType, geocodeUrl, logoUrl, simulateAt = null, maxAccuracy = 100 }) {
    return {
        punchType,
        stream: null,
        watchId: null,
        clockTimer: null,
        cameraReady: false,
        cameraError: null,
        // 'user' (front, the selfie) or 'environment' (back); the switch shows only on phones with both.
        facing: 'user',
        canSwitch: false,
        locationError: null,
        coords: null,
        address: null,
        // Where the last address lookup was for, so a stale address isn't shown or burned in.
        addressAt: null,
        lastLookupAt: 0,
        clockText: '',
        photo: null,
        // What Capture saw: { latitude, longitude, accuracy, address }. Burned in and submitted.
        shot: null,
        capturing: false,
        submitting: false,
        message: null,
        logo: null,
        // The phone's model code (e.g. "SM-A156E"), from Chrome / Samsung Internet / Edge on
        // Android; iPhones and Firefox don't give one. Shown with its brand to supervisors.
        model: null,

        get canCapture() {
            return this.cameraReady && !this.capturing && this.coords !== null && (this.coords.accuracy ?? 0) <= maxAccuracy;
        },

        // The address only while it belongs to where the phone is now.
        get currentAddress() {
            if (!this.address || !this.addressAt || !this.coords) return null;

            return metersBetween(this.addressAt, this.coords) <= ADDRESS_FRESH_METERS ? this.address : null;
        },

        // Shown as the Capture button's tooltip while it is locked.
        get captureBlockedReason() {
            if (this.capturing) return 'Getting the street address for this exact spot, to print on the photo.';
            if (!this.cameraReady) return 'Waiting for the camera. Allow camera access if your browser asks.';
            if (this.coords === null) return 'Waiting for your GPS location. Allow location access and stay outdoors or near a window.';
            // The first fix is often rough (Wi-Fi or cell towers); the phone sharpens it within seconds.
            if ((this.coords.accuracy ?? 0) > maxAccuracy) return `Getting a precise GPS fix (now ±${Math.round(this.coords.accuracy)} m, needs ±${maxAccuracy} m). Step outside or near a window.`;
            return '';
        },

        get label() {
            return this.punchType === 'clock_in' ? 'CLOCK IN' : 'CLOCK OUT';
        },

        get coordsText() {
            return this.formatCoords(this.shot ?? this.coords);
        },

        formatCoords(at) {
            if (!at) return '';

            return `${at.latitude.toFixed(6)}, ${at.longitude.toFixed(6)} (±${Math.round(at.accuracy ?? 0)} m)`;
        },

        init() {
            this.tickClock();
            this.clockTimer = setInterval(() => this.tickClock(), 1000);
            this.logo = new Image();
            this.logo.src = logoUrl;
            this.startCamera();
            this.startLocation();
            navigator.userAgentData?.getHighEntropyValues?.(['model'])
                .then((values) => { this.model = values.model || null; })
                .catch(() => {});
        },

        destroy() {
            clearInterval(this.clockTimer);
            this.stream?.getTracks().forEach((track) => track.stop());
            if (this.watchId !== null) navigator.geolocation?.clearWatch(this.watchId);
        },

        tickClock() {
            this.clockText = new Date().toLocaleString('en-PH');
        },

        async startCamera() {
            this.cameraError = null;
            this.cameraReady = false;

            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                this.cameraError = 'The camera needs HTTPS. Open this site with https://';
                return;
            }

            // A phone opens one camera at a time: release the current one before asking for the other.
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: this.facing } }, audio: false });
                this.$refs.video.srcObject = this.stream;
                // Ready only once a real frame plays: before that, videoWidth is 0 and the photo would be black.
                await this.$refs.video.play();
                this.cameraReady = true;
                // Device labels and counts are only reliable once camera access is granted.
                const devices = await navigator.mediaDevices.enumerateDevices().catch(() => []);
                this.canSwitch = devices.filter((device) => device.kind === 'videoinput').length > 1;
            } catch (error) {
                this.cameraError = `Camera unavailable: ${error.message}`;
            }
        },

        async switchCamera() {
            if (!this.canSwitch || this.photo) return;

            this.facing = this.facing === 'user' ? 'environment' : 'user';
            await this.startCamera();
        },

        startLocation() {
            if (!navigator.geolocation) {
                this.locationError = 'This browser cannot share its location.';
                return;
            }

            this.watchId = navigator.geolocation.watchPosition(
                (position) => {
                    this.coords = position.coords;
                    this.locationError = null;
                    this.lookupAddress(position.coords.latitude, position.coords.longitude);
                },
                (error) => {
                    this.locationError = `Location error: ${error.message}`;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 },
            );
        },

        // While watching: at most every 15 s (the server also caches and rate-limits).
        // At Capture (force): now, for exactly the captured position.
        async lookupAddress(lat, lon, force = false) {
            if (!force && Date.now() - this.lastLookupAt < 15000) return;
            this.lastLookupAt = Date.now();

            try {
                const response = await fetch(`${geocodeUrl}?lat=${lat}&lon=${lon}`, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                this.address = response.ok ? data.address : null;
                this.addressAt = response.ok ? { latitude: lat, longitude: lon } : null;
            } catch {
                this.address = null;
                this.addressAt = null;
            }
        },

        async capture() {
            if (!this.canCapture) return;

            this.capturing = true;
            // Freeze the position first; the GPS keeps moving while the address is fetched.
            const at = { latitude: this.coords.latitude, longitude: this.coords.longitude, accuracy: this.coords.accuracy };

            try {
                if (!this.addressAt || metersBetween(this.addressAt, at) > ADDRESS_FRESH_METERS) {
                    await this.lookupAddress(at.latitude, at.longitude, true);
                }
                const fresh = this.addressAt && metersBetween(this.addressAt, at) <= ADDRESS_FRESH_METERS;
                this.shot = { ...at, address: fresh ? this.address : null };

                const video = this.$refs.video;
                const scale = Math.min(1, MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
                this.drawAndSave(video, Math.round(video.videoWidth * scale), Math.round(video.videoHeight * scale));
            } finally {
                this.capturing = false;
            }
        },

        // Local development only: lets you punch from a desktop without a camera or GPS.
        // It stands at the post (simulateAt), so the server's geofence lets it through.
        simulate() {
            this.coords = { latitude: simulateAt?.latitude ?? 14.5995, longitude: simulateAt?.longitude ?? 120.9842, accuracy: 5 };
            this.address = simulateAt ? 'At the post (simulated)' : 'Manila, Philippines (simulated)';
            this.addressAt = { latitude: this.coords.latitude, longitude: this.coords.longitude };
            this.shot = { ...this.coords, address: this.address };

            const canvas = document.createElement('canvas');
            canvas.width = 480;
            canvas.height = 640;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#1f2937';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            this.drawAndSave(canvas, canvas.width, canvas.height);
        },

        drawAndSave(source, width, height) {
            const canvas = this.$refs.canvas;
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(source, 0, 0, width, height);

            this.burnTimemark(ctx, width, height);

            this.photo = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
            this.message = null;
        },

        burnTimemark(ctx, width, height) {
            const pad = Math.round(width * 0.03);
            const big = Math.max(14, Math.round(width * 0.032));
            const small = Math.max(11, Math.round(width * 0.022));

            if (this.logo?.complete && this.logo.naturalWidth > 0) {
                const logoWidth = Math.round(width * 0.3);
                const logoHeight = Math.round(logoWidth * (this.logo.naturalHeight / this.logo.naturalWidth));
                ctx.fillStyle = 'rgba(255,255,255,0.92)';
                ctx.beginPath();
                ctx.roundRect(pad, pad, logoWidth + pad, logoHeight + pad, pad / 2);
                ctx.fill();
                ctx.drawImage(this.logo, pad * 1.5, pad * 1.5, logoWidth, logoHeight);
            }

            const lines = [this.shot?.address ?? 'Address unavailable', this.formatCoords(this.shot)];
            const boxHeight = pad * 2 + big * 1.6 + lines.length * small * 1.4;
            const boxY = height - boxHeight - pad;

            ctx.fillStyle = 'rgba(0,0,0,0.6)';
            ctx.beginPath();
            ctx.roundRect(pad, boxY, width - pad * 2, boxHeight, pad / 2);
            ctx.fill();

            ctx.textBaseline = 'top';
            ctx.font = `700 ${big}px sans-serif`;
            ctx.fillStyle = this.punchType === 'clock_in' ? '#60a5fa' : '#fbbf24';
            ctx.fillText(this.label, pad * 2, boxY + pad);

            ctx.font = `600 ${Math.round(big * 0.8)}px sans-serif`;
            ctx.fillStyle = '#ffffff';
            ctx.fillText(this.clockText, pad * 2 + ctx.measureText(this.label).width + big * 2.2, boxY + pad + big * 0.15);

            ctx.font = `400 ${small}px sans-serif`;
            lines.forEach((line, index) => {
                ctx.fillText(line.slice(0, 90), pad * 2, boxY + pad + big * 1.6 + index * small * 1.4);
            });
        },

        retake() {
            this.photo = null;
            this.shot = null;
            this.message = null;
        },

        // Sends exactly what the photo shows (the snapshot), not where the GPS has drifted since.
        async submit() {
            if (!this.photo || !this.shot || this.submitting) return;

            this.submitting = true;
            this.message = null;

            try {
                const result = await this.$wire.submitPunch(
                    this.punchType,
                    this.photo,
                    this.shot.latitude,
                    this.shot.longitude,
                    this.shot.accuracy ? Math.round(this.shot.accuracy) : null,
                    this.shot.address,
                    this.model,
                );

                if (result?.ok) {
                    this.$dispatch('close-punch-modal');
                } else {
                    this.message = result?.message ?? 'The punch could not be saved. Please try again.';
                }
            } catch {
                this.message = 'Network error. Check your connection and try again.';
            } finally {
                this.submitting = false;
            }
        },
    };
}
