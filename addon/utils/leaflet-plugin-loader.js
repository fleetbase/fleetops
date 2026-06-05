import { debug } from '@ember/debug';
import ensureLeafletDrawEditNamespace from './leaflet-draw-namespace-guard';

const DEFAULT_BASE_PATH = 'engines-dist/leaflet';
const DEFAULT_SCRIPTS = ['leaflet.contextmenu.js', 'leaflet.draw-src.js'];
const DEFAULT_STYLESHEETS = ['leaflet.contextmenu.css', 'leaflet.draw.css'];
const SCRIPT_FLAG = 'fleetopsLeafletPluginLoaded';
const STYLESHEET_FLAG = 'fleetopsLeafletPluginStylesheet';

let pluginReadyPromise = null;

function normalizePath(path = '') {
    return `/${path.replace(/^\/+/, '')}`;
}

function assetPath(basePath, assetName) {
    return normalizePath(`${basePath ? `${basePath}/` : ''}${assetName}`);
}

function findAssetElement(tagName, srcAttribute, src) {
    return Array.from(document.getElementsByTagName(tagName)).find((element) => {
        const currentSrc = element.getAttribute(srcAttribute);
        if (currentSrc === src) {
            return true;
        }

        try {
            return new URL(element[srcAttribute], window.location.origin).pathname === src;
        } catch {
            return false;
        }
    });
}

function normalizeLeafletGlobal() {
    if (typeof window === 'undefined') {
        return null;
    }

    const leaflet = window.L || window.leaflet;
    if (!leaflet) {
        return null;
    }

    window.L = leaflet;
    window.leaflet = leaflet;
    ensureLeafletDrawEditNamespace(leaflet);
    return leaflet;
}

function hasLeafletDrawPlugins(leaflet = normalizeLeafletGlobal()) {
    return Boolean(leaflet?.Edit?.Marker && leaflet?.Edit?.Poly && leaflet?.Control?.Draw);
}

function hasLeafletContextmenuPlugin(leaflet = normalizeLeafletGlobal()) {
    return Boolean(leaflet?.Map?.ContextMenu);
}

export function hasLeafletPluginsReady() {
    const leaflet = normalizeLeafletGlobal();
    return Boolean(leaflet && hasLeafletDrawPlugins(leaflet) && hasLeafletContextmenuPlugin(leaflet));
}

function waitForLeafletGlobal({ timeoutMs = 8000 } = {}) {
    const leaflet = normalizeLeafletGlobal();
    if (leaflet) {
        return Promise.resolve(leaflet);
    }

    return new Promise((resolve, reject) => {
        const startedAt = Date.now();
        const interval = setInterval(() => {
            const leaflet = normalizeLeafletGlobal();
            if (leaflet) {
                clearInterval(interval);
                resolve(leaflet);
                return;
            }

            if (timeoutMs != null && Date.now() - startedAt >= timeoutMs) {
                clearInterval(interval);
                reject(new Error('[FleetOps Leaflet] Leaflet global is not available'));
            }
        }, 50);
    });
}

function appendStylesheet(href) {
    if (typeof document === 'undefined') {
        return;
    }

    const existing = findAssetElement('link', 'href', href);
    if (existing) {
        existing.dataset[STYLESHEET_FLAG] = 'true';
        return;
    }

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset[STYLESHEET_FLAG] = 'true';
    document.head.appendChild(link);
}

function loadScript(src, { timeoutMs = 8000, isReady = null } = {}) {
    if (typeof document === 'undefined') {
        return Promise.reject(new Error('[FleetOps Leaflet] document is not available'));
    }

    const existing = findAssetElement('script', 'src', src);
    if (existing?.dataset[SCRIPT_FLAG] === 'true' || isReady?.()) {
        if (existing) {
            existing.dataset[SCRIPT_FLAG] = 'true';
        }
        return Promise.resolve(existing);
    }

    return new Promise((resolve, reject) => {
        const script = existing ?? document.createElement('script');
        let timeoutId;

        const cleanup = () => {
            script.removeEventListener('load', onLoad);
            script.removeEventListener('error', onError);
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
        };

        const onLoad = () => {
            cleanup();
            script.dataset[SCRIPT_FLAG] = 'true';
            normalizeLeafletGlobal();
            resolve(script);
        };

        const onError = () => {
            cleanup();
            reject(new Error(`[FleetOps Leaflet] Failed to load ${src}`));
        };

        script.addEventListener('load', onLoad);
        script.addEventListener('error', onError);

        if (timeoutMs != null) {
            timeoutId = setTimeout(() => {
                cleanup();
                reject(new Error(`[FleetOps Leaflet] Timed out loading ${src}`));
            }, timeoutMs);
        }

        if (!existing) {
            script.src = src;
            script.async = false;
            script.dataset.fleetopsLeafletPlugin = 'true';
            document.body.appendChild(script);
        }
    });
}

export function resetLeafletPluginLoaderForTesting() {
    pluginReadyPromise = null;
}

function pluginReadyCheck(script) {
    if (script.includes('contextmenu')) {
        return () => hasLeafletContextmenuPlugin();
    }

    if (script.includes('draw')) {
        return () => hasLeafletDrawPlugins();
    }

    return null;
}

export default function ensureLeafletPluginsReady(options = {}) {
    const { basePath = DEFAULT_BASE_PATH, scripts = DEFAULT_SCRIPTS, stylesheets = DEFAULT_STYLESHEETS, timeoutMs = 8000, force = false } = options;

    if (!force && hasLeafletPluginsReady()) {
        window.fleetopsLeafletPluginsLoaded = true;
        return Promise.resolve(normalizeLeafletGlobal());
    }

    if (!force && pluginReadyPromise) {
        return pluginReadyPromise;
    }

    window.fleetopsLeafletPluginsLoaded = false;
    stylesheets.forEach((stylesheet) => appendStylesheet(assetPath(basePath, stylesheet)));

    pluginReadyPromise = waitForLeafletGlobal({ timeoutMs })
        .then(() =>
            scripts.reduce((promise, script) => {
                return promise.then(() => {
                    normalizeLeafletGlobal();
                    return loadScript(assetPath(basePath, script), { timeoutMs, isReady: pluginReadyCheck(script) });
                });
            }, Promise.resolve())
        )
        .then(() => {
            const readyLeaflet = normalizeLeafletGlobal();
            if (!hasLeafletPluginsReady()) {
                throw new Error('[FleetOps Leaflet] Leaflet plugins loaded but required Draw/contextmenu APIs are missing');
            }

            window.fleetopsLeafletPluginsLoaded = true;
            return readyLeaflet;
        })
        .catch((error) => {
            window.fleetopsLeafletPluginsLoaded = false;
            pluginReadyPromise = null;
            debug(error.message);
            throw error;
        });

    return pluginReadyPromise;
}
