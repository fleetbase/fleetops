export function initialize() {
    const path = '/engines-dist/leaflet/';
    const scripts = ['leaflet.contextmenu.js', 'leaflet.draw-src.js', 'leaflet.rotatedMarker.js', 'leaflet-drift-marker.js'];
    const stylesheets = ['leaflet.contextmenu.css', 'leaflet.draw.css'];

    // Define exports on window
    const exportsScript = document.createElement('script');
    exportsScript.innerHTML = 'window.exports = window.exports || {};';
    document.body.appendChild(exportsScript);

    for (let i = 0; i < scripts.length; i++) {
        const script = document.createElement('script');
        script.src = path + scripts[i];
        document.body.appendChild(script);
    }

    for (let i = 0; i < stylesheets.length; i++) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = path + stylesheets[i];
        document.body.appendChild(link);
    }
}

export default {
    initialize,
};
