// A small Leaflet map with the point of a location answer: street map or satellite view, and the
// GPS accuracy as a circle. Shared by the form and the response page; needs Leaflet (window.L).
(function () {
    'use strict';

    const maps = new WeakMap();

    window.locationMap = function (element, lat, lng, accuracy = 0) {
        if (!window.L) return false;
        const point = [lat, lng];
        let entry = maps.get(element);
        if (!entry) {
            const street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            });
            const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Citra &copy; Esri',
            });
            const map = L.map(element, { layers: [street], scrollWheelZoom: false });
            L.control.layers({ Peta: street, Satelit: satellite }, null, { collapsed: false }).addTo(map);
            entry = {
                map,
                marker: L.marker(point).addTo(map),
                circle: L.circle(point, { radius: 0, weight: 1, fillOpacity: 0.12 }).addTo(map),
            };
            maps.set(element, entry);
        }
        entry.marker.setLatLng(point);
        entry.circle.setLatLng(point).setRadius(accuracy || 0);
        entry.map.invalidateSize();
        entry.map.setView(point, 17);

        return true;
    };

    document.querySelectorAll('[data-map-point]').forEach(element => {
        const [lat, lng] = element.dataset.mapPoint.split(',').map(Number);
        window.locationMap(element, lat, lng);
    });
})();
