import loadLeaflet from './leaflet-loader';

// Leaflet (loaded when a map is first opened, leaflet-loader.js).
let L = null;

/**
 * The map in the "where is this person" window (Patrols / Office Away): the
 * post or office and its circle, the trail since they left, and the latest
 * spot with its GPS accuracy. OpenStreetMap tiles; markers are drawn
 * circles, so no marker images need bundling.
 *
 * `data` = { post: {lat, lng, radius, name} | null, points: [{lat, lng, accuracy, label, kind}] }
 * in time order; points without coordinates (e.g. "needed more time") are
 * skipped. The last point with coordinates is the latest spot.
 *
 * It opens fitted to the trail, with the post too when it's near (a far
 * post would shrink the trail to one dot). The timeline next to it sends
 * `away-map-focus` with a point's index to show that spot.
 *
 * Leaflet objects live in this closure, not on the Alpine component:
 * Alpine's reactive proxies break Leaflet's internals.
 */
export default function awayMap(data) {
    const blue = '#2563eb';
    const amber = '#d97706';
    const gray = '#6b7280';
    // The post is shown on open only when it's within this distance of the trail.
    const nearPostMeters = 3000;

    let map = null;
    let gone = false;
    let markers = {};
    let focused = null;
    let latestMarker = null;
    let trail = [];

    // Back to the resting state: no point highlighted, the latest spot labelled.
    const unfocus = () => {
        focused?.setStyle({ weight: 2, color: '#fff' });
        focused = null;
        latestMarker?.openTooltip();
    };

    const fit = (bounds) => {
        if (bounds.length === 1) {
            map.setView(bounds[0], 17);
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 });
        } else {
            map.setView([14.5995, 120.9842], 11); // Manila, when nothing has coordinates
        }
    };

    return {
        hasPost: data.post !== null,
        postFar: false,

        init() {
            // The window opens with a transition: wait until it has its size.
            setTimeout(() => this.draw(), 120);
        },

        destroy() {
            gone = true;
            map?.remove();
            map = null;
            markers = {};
            focused = null;
        },

        async draw() {
            if (!this.$refs.map) return;

            L = await loadLeaflet();
            // Closed while Leaflet was loading.
            if (gone || !this.$refs.map?.isConnected) return;

            map = L.map(this.$refs.map, { scrollWheelZoom: false, attributionControl: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(map);

            if (data.post) {
                const post = [data.post.lat, data.post.lng];
                L.circle(post, { radius: data.post.radius, color: blue, weight: 1, fillColor: blue, fillOpacity: 0.08 }).addTo(map);
                L.circleMarker(post, { radius: 7, color: '#fff', weight: 2, fillColor: blue, fillOpacity: 1 })
                    .bindTooltip(data.post.name, { direction: 'top' })
                    .addTo(map);
            }

            const located = data.points
                .map((point, index) => ({ ...point, index }))
                .filter((point) => point.lat !== null && point.lng !== null);
            trail = located.map((point) => [point.lat, point.lng]);

            if (located.length > 1) {
                L.polyline(trail, { color: gray, weight: 2, dashArray: '4 6' }).addTo(map);
            }

            located.forEach((point, position) => {
                const latest = position === located.length - 1;
                const at = [point.lat, point.lng];

                if (latest && point.accuracy) {
                    L.circle(at, { radius: point.accuracy, color: amber, weight: 1, fillColor: amber, fillOpacity: 0.12 }).addTo(map);
                }

                markers[point.index] = L.circleMarker(at, {
                    radius: latest ? 8 : 5,
                    color: '#fff',
                    weight: 2,
                    fillColor: latest ? amber : gray,
                    fillOpacity: 1,
                })
                    .bindTooltip(point.label, { direction: 'top', permanent: latest })
                    .addTo(map);

                if (latest) latestMarker = markers[point.index];
            });

            if (data.post && trail.length > 0) {
                const post = L.latLng(data.post.lat, data.post.lng);
                this.postFar = Math.min(...trail.map((at) => post.distanceTo(at))) > nearPostMeters;
            }

            this.showTrail();
        },

        /** The whole trail, plus the post when it's near. */
        showTrail() {
            if (!map) return;
            unfocus();
            fit(data.post && !this.postFar ? [...trail, [data.post.lat, data.post.lng]] : trail);
        },

        /** The trail and the post together, however far apart. */
        showPost() {
            if (!map || !data.post) return;
            unfocus();
            fit([...trail, [data.post.lat, data.post.lng]]);
        },

        /** One point of the timeline: centre it and say what it is. */
        focus(index) {
            const marker = markers[index];
            if (!map || !marker) return;

            unfocus();
            // Only one label at a time: the latest spot's permanent one would cover this one.
            if (marker !== latestMarker) latestMarker?.closeTooltip();
            marker.setStyle({ weight: 3, color: blue });
            focused = marker;
            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 17));
            marker.openTooltip();
            this.$refs.map.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },
    };
}
