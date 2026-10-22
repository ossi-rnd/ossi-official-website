import loadLeaflet from './leaflet-loader';

/**
 * A client's post on the Add / Edit client form: where guards must be to clock in.
 *
 *  - "Use my current location": standing at the post, the phone's GPS fills the
 *    coordinates (with how precise the fix is);
 *  - a map with a draggable pin and the geofence circle: check the circle covers the
 *    post, drag or click to adjust; works from the office too.
 *
 * Both write the same "latitude, longitude" text the field always took (Clients::save
 * reads it), and typing or pasting coordinates moves the pin. Leaflet objects live in
 * this closure, not on the Alpine component (its proxies break Leaflet).
 */
const MANILA = [14.5995, 120.9842];
// A rougher GPS fix than this is shown as a warning: the pin may be off the post.
const ROUGH_METERS = 30;

function parse(text) {
    const match = String(text ?? '').match(/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/);

    return match ? [parseFloat(match[1]), parseFloat(match[2])] : null;
}

export default function sitePicker() {
    let L = null;
    let map = null;
    let pin = null;
    let circle = null;
    let gone = false;
    let lastRadius = null;

    // The whole geofence circle in view, with a margin, so its edge can be checked.
    const showCircle = (at, radius) => map.fitBounds(L.latLng(at).toBounds(radius * 2.6), { maxZoom: 19 });

    // Full screen changes the map's size: Leaflet must measure again, and keep the pin in view.
    // Zooming with the mouse wheel only in full screen (in the form it would hijack scrolling).
    const resized = (full) => setTimeout(() => {
        if (!map) return;
        map.invalidateSize();
        full ? map.scrollWheelZoom.enable() : map.scrollWheelZoom.disable();
        if (pin) map.panTo(pin.getLatLng());
    }, 60);

    return {
        locating: false,
        accuracy: null,
        locateError: null,
        // The map over the whole screen, to place the pin precisely.
        full: false,

        get rough() {
            return this.accuracy !== null && this.accuracy > ROUGH_METERS;
        },

        async init() {
            this.$watch('$wire.coordinates', () => this.sync());
            this.$watch('$wire.geofence_meters', () => this.sync());

            L = await loadLeaflet();
            // The window opens with a transition: draw once it has its size.
            setTimeout(() => this.draw(), 150);
        },

        /**
         * The browser's real full screen where it allows it (Esc leaves it); otherwise (an
         * iPhone allows it for videos only) the box just covers the screen.
         */
        async toggleFull() {
            const box = this.$refs.mapBox;

            if (this.full) {
                if (document.fullscreenElement) await document.exitFullscreen().catch(() => {});
                this.full = false;
                resized(false);
                return;
            }

            this.full = true;
            if (box.requestFullscreen) await box.requestFullscreen().catch(() => {});
            resized(true);
        },

        /** Left with Esc or the browser's own control: back to the form. */
        fullscreenChanged() {
            if (!document.fullscreenElement && this.full) {
                this.full = false;
                resized(false);
            }
        },

        destroy() {
            gone = true;
            if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
            map?.remove();
            map = pin = circle = null;
        },

        draw() {
            if (gone || !this.$refs.map?.isConnected) return;

            map = L.map(this.$refs.map, { scrollWheelZoom: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(map);
            map.setView(MANILA, 11);
            map.on('click', (event) => this.place(event.latlng.lat, event.latlng.lng));
            this.sync();
        },

        /** Pin and circle follow the field (typed, pasted, dragged or from GPS). */
        sync() {
            if (!map) return;

            const at = parse(this.$wire.coordinates);
            const radius = parseInt(this.$wire.geofence_meters, 10) || 150;

            if (!at) {
                pin?.remove();
                circle?.remove();
                pin = circle = null;
                return;
            }

            if (pin) {
                pin.setLatLng(at);
                circle.setLatLng(at).setRadius(radius);
                // A new geofence size, or a place typed or pasted off-screen: show the whole circle again.
                if (radius !== lastRadius || !map.getBounds().contains(at)) showCircle(at, radius);
                lastRadius = radius;
                return;
            }

            // A drawn dot, not Leaflet's image marker (its images don't survive bundling).
            const icon = L.divIcon({ className: '', html: '<span class="block h-5 w-5 rounded-full border-2 border-white bg-blue-600 shadow"></span>', iconSize: [20, 20], iconAnchor: [10, 10] });
            pin = L.marker(at, { draggable: true, icon, title: 'Post: drag to adjust', keyboard: true }).addTo(map);
            pin.on('dragend', () => {
                const moved = pin.getLatLng();
                this.place(moved.lat, moved.lng);
            });
            circle = L.circle(at, { radius, color: '#2563eb', weight: 2, fillColor: '#2563eb', fillOpacity: 0.12, interactive: false }).addTo(map);
            showCircle(at, radius);
            lastRadius = radius;
        },

        place(latitude, longitude) {
            this.$wire.coordinates = `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
        },

        locate() {
            this.locateError = null;

            if (!navigator.geolocation || !window.isSecureContext) {
                this.locateError = 'This browser can\'t share its location here. Paste the coordinates instead.';
                return;
            }

            this.locating = true;
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    this.locating = false;
                    this.accuracy = Math.round(position.coords.accuracy);
                    this.place(position.coords.latitude, position.coords.longitude);
                    if (map) showCircle([position.coords.latitude, position.coords.longitude], parseInt(this.$wire.geofence_meters, 10) || 150);
                },
                (error) => {
                    this.locating = false;
                    this.locateError = error.code === 1
                        ? 'Location access was blocked. Allow it for this site in the browser, then try again.'
                        : 'Couldn\'t get a GPS fix. Step outside or near a window and try again.';
                },
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
            );
        },
    };
}
