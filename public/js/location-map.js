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

    // All responses' points on one map (responses page): numbered pins matching the table's "No",
    // the GPS accuracy as circles, and hover linking pins with [data-point-id] rows and list items.
    const escape = text => String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const linked = id => document.querySelectorAll(`[data-point-id="${CSS.escape(String(id))}"]`);

    function pointsMap(element, points) {
        const street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Citra &copy; Esri' });
        const map = L.map(element, { layers: [street], scrollWheelZoom: false });
        L.control.layers({ Peta: street, Satelit: satellite }, null, { collapsed: false }).addTo(map);

        const markers = new Map();
        points.forEach(point => {
            const circle = point.accuracy ? L.circle([point.lat, point.lng], { radius: point.accuracy, weight: 1, color: '#087e78', fillOpacity: 0.1, interactive: false }).addTo(map) : null;
            const marker = L.marker([point.lat, point.lng], {
                icon: L.divIcon({ className: 'map-pin', html: `<span>${escape(point.number)}</span>`, iconSize: [30, 30], iconAnchor: [15, 38], tooltipAnchor: [0, -38] }),
                title: point.label,
                riseOnHover: true,
            }).addTo(map);
            marker.bindTooltip(
                `<strong>${escape(point.number)}. ${escape(point.label)}</strong><br>${escape(point.time)}`
                + (point.accuracy ? `<br>Akurasi ±${escape(point.accuracy)} m` : '')
                + `<br><small>${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}</small>`,
                { direction: 'top', className: 'map-tooltip' },
            );
            marker.on('mouseover', () => highlight(point.id, true, false));
            marker.on('mouseout', () => highlight(point.id, false, false));
            marker.on('click', () => { window.location.href = point.url; });
            markers.set(String(point.id), { marker, circle });
        });

        const bounds = L.latLngBounds(points.map(point => [point.lat, point.lng]));
        map.fitBounds(bounds.pad(0.2), { maxZoom: 18 });

        // fromList: the pointer is on a row or list item, so also show the pin's tooltip.
        function highlight(id, on, fromList) {
            const entry = markers.get(String(id));
            if (!entry) return;
            entry.marker.getElement()?.classList.toggle('is-active', on);
            entry.marker.setZIndexOffset(on ? 1000 : 0);
            entry.circle?.setStyle({ weight: on ? 2 : 1, fillOpacity: on ? 0.22 : 0.1 });
            if (fromList) on ? entry.marker.openTooltip() : entry.marker.closeTooltip();
            linked(id).forEach(row => row.classList.toggle('is-highlighted', on));
        }

        return { map, highlight, has: id => markers.has(String(id)) };
    }

    const pointMaps = [];
    document.querySelectorAll('[data-map-points]').forEach(element => {
        const points = JSON.parse(element.dataset.mapPoints || '[]');
        if (window.L && points.length) pointMaps.push(pointsMap(element, points));
    });
    if (pointMaps.length) {
        document.addEventListener('mouseover', event => {
            const row = event.target.closest('[data-point-id]');
            if (!row || row.contains(event.relatedTarget)) return;
            pointMaps.forEach(entry => entry.highlight(row.dataset.pointId, true, true));
        });
        document.addEventListener('mouseout', event => {
            const row = event.target.closest('[data-point-id]');
            if (!row || row.contains(event.relatedTarget)) return;
            pointMaps.forEach(entry => entry.highlight(row.dataset.pointId, false, true));
        });
    }
})();
