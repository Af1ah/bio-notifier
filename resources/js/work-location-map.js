import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export function mountPicker(element, options) {
    const hasPoint = () => options.latitude !== null && options.latitude !== '' && options.longitude !== null && options.longitude !== '';
    const map = L.map(element, { scrollWheelZoom: false });
    map.setView(hasPoint() ? [Number(options.latitude), Number(options.longitude)] : options.center, hasPoint() ? 17 : 5);
    L.tileLayer(options.tileUrl, {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    let pin, circle, disposed = false;
    function sync(latitude, longitude, radius) {
        if (latitude === null || latitude === '' || longitude === null || longitude === '') return;
        const point = [Number(latitude), Number(longitude)];
        if (!Number.isFinite(point[0]) || !Number.isFinite(point[1]) || Math.abs(point[0]) > 90 || Math.abs(point[1]) > 180) return;
        if (!pin) {
            pin = L.marker(point, { draggable: true, icon: L.divIcon({
                className: '', html: '<span style="display:block;width:20px;height:20px;background:#f59e0b;border:3px solid white;border-radius:50%;box-shadow:0 0 0 2px #92400e"></span>',
                iconSize: [20, 20], iconAnchor: [10, 10],
            }) }).addTo(map);
            pin.on('dragend', () => options.onChange(map.wrapLatLng(pin.getLatLng())));
            circle = L.circle(point, { radius: Number(radius) || 100, color: '#f59e0b', fillOpacity: 0.12 }).addTo(map);
        }
        pin.setLatLng(point);
        circle.setLatLng(point).setRadius(Math.max(10, Number(radius) || 100));
    }
    sync(options.latitude, options.longitude, options.radius);
    map.on('click', event => options.onChange(map.wrapLatLng(event.latlng)));
    const resize = new ResizeObserver(() => map.invalidateSize());
    resize.observe(element);
    // Center locally without choosing or saving a pin until the user acts.
    if (!hasPoint() && navigator.permissions && navigator.geolocation) {
        navigator.permissions.query({ name: 'geolocation' }).then(permission => {
            if (!disposed && permission.state === 'granted') navigator.geolocation.getCurrentPosition(position => {
                if (!disposed && element.isConnected && !pin) map.setView([position.coords.latitude, position.coords.longitude], 16);
            }, () => {}, { maximumAge: 60000, timeout: 10000 });
        }).catch(() => {});
    }
    return {
        sync,
        center: (lat, lng) => map.setView([lat, lng], 17),
        destroy: () => { disposed = true; resize.disconnect(); map.remove(); },
    };
}
