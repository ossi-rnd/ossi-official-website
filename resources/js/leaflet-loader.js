/**
 * Leaflet and its CSS (~150 KB), loaded the first time a map is shown (the away
 * map, the client site picker), not with every page. One shared load.
 */
let leaflet = null;

export default function loadLeaflet() {
    leaflet ??= Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]).then(([module]) => module.default);

    return leaflet;
}
