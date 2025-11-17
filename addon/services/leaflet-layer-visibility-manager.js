import Service, { inject as service } from '@ember/service';
import { next } from '@ember/runloop';
import { isNone } from '@ember/utils';

const L = window.L || window.leaflet;
export default class LeafletLayerVisibilityService extends Service {
    @service leafletMapManager;
    #renderersByPane = new Map();
    #byCategory = new Map();
    #byId = new Map();
    #paneState = new Map();

    get map() {
        return this.leafletMapManager?.map;
    }

    /** Try to get the actual DOM element for any Leaflet layer type */
    #getLayerEl(layer) {
        return layer?.getElement?.() || layer?._path || layer?._icon || null;
    }

    /** Hide popups/tooltips and remember whether they were open */
    /* eslint-disable no-empty */
    #hideOverlays(layer, remember = true) {
        next(() => {
            // Safely fetch tooltip/popup (may be undefined)
            const tt = layer.getTooltip?.() ?? layer._tooltip ?? null;
            const pp = layer.getPopup?.() ?? layer._popup ?? null;

            if (remember) {
                // Only check open-state if the instance exists
                layer.__hadOpenTooltip = !!(tt && tt.isOpen && tt.isOpen());
                layer.__hadOpenPopup = !!(pp && pp.isOpen && pp.isOpen());
            }

            // Close via API if present
            try {
                if (tt) layer.closeTooltip?.();
            } catch {}
            try {
                if (pp) layer.closePopup?.();
            } catch {}

            // Also hard-hide DOM containers if they exist
            if (tt?._container) tt._container.style.display = 'none';
            if (pp?._container) pp._container.style.display = 'none';
        });
    }

    #showOverlays(layer) {
        const tt = layer.getTooltip?.() || layer._tooltip || null;
        const pp = layer.getPopup?.() || layer._popup || null;

        // Unhide DOM containers if they exist
        if (tt?._container) tt._container.style.display = '';
        if (pp?._container) pp._container.style.display = '';

        // Re-open only if they exist and were previously open (or tooltip is permanent)
        if (tt) {
            const shouldOpen = layer.__hadOpenTooltip || (tt.options && tt.options.permanent);
            if (shouldOpen) {
                try {
                    layer.openTooltip?.();
                } catch {}
            }
        }

        if (pp && layer.__hadOpenPopup) {
            try {
                layer.openPopup?.();
            } catch {}
        }

        delete layer.__hadOpenTooltip;
        delete layer.__hadOpenPopup;
    }

    /** Ensure a dedicated pane exists for a category; returns the pane name */
    ensurePane(category, { zIndex = 400 } = {}) {
        if (!this.map) return null;
        const paneName = `pane-${category}`;
        let pane = this.map.getPane(paneName);
        if (!pane) {
            pane = this.map.createPane(paneName);
            pane.style.zIndex = String(zIndex);
        }
        return { pane, paneName };
    }

    #getOrCreateRendererForPane(paneName, { useCanvas = false } = {}) {
        let renderer = this.#renderersByPane.get(paneName);
        if (!renderer) {
            renderer = useCanvas ? L.canvas({ pane: paneName }) : L.svg({ pane: paneName });
            renderer.addTo(this.map); // attach renderer layer to map in that pane
            this.#renderersByPane.set(paneName, renderer);
        }
        return renderer;
    }

    /** Assign a layer to a category pane (call this once when the layer is created/added) */
    assignPane(layer, category) {
        const paneName = this.ensurePane(category);
        if (!paneName || !layer) return;
        // move the layer into the pane; a redraw typically moves it immediately
        layer.options = { ...(layer.options || {}), pane: paneName };
        try {
            layer.redraw?.();
        } catch {}
    }

    /** Internal: toggle pane display */
    #setPaneVisible(category, visible) {
        const pane = this.map?.getPane?.(`pane-${category}`);
        if (pane) pane.style.display = visible ? '' : 'none';
    }

    /** Public API: show/hide a whole category */
    showCategory(category) {
        this.#setPaneVisible(category, true);
    }

    hideCategory(category) {
        this.#setPaneVisible(category, false);
    }

    toggleCategory(category) {
        if (this.isCategoryVisible(category)) {
            this.hideCategory(category);
        } else {
            this.showCategory(category);
        }
    }

    showAll({ soft = false } = {}) {
        // Make every category's pane visible
        for (const category of this.#byCategory.keys()) {
            this.#setPaneVisible(category, true);

            // Also ensure each layer is shown (soft or hard)
            const set = this.#byCategory.get(category);
            if (!set) continue;
            for (const layer of set) {
                this.showLayer(layer, { soft });
            }
        }
    }

    /** Hide all registered layers (by category). */
    hideAll({ soft = false } = {}) {
        for (const category of this.#byCategory.keys()) {
            const set = this.#byCategory.get(category);
            if (!set) continue;

            if (soft) {
                // Soft-hide every layer (keeps on map, just invisible)
                for (const layer of set) {
                    this.hideLayer(layer, { soft: true });
                }
            } else {
                // Hard-hide via pane (fast) + close overlays to avoid stray tooltips/popups
                for (const layer of set) {
                    this.#hideOverlays(layer, /* remember */ false);
                }
                this.#setPaneVisible(category, false);
            }
        }
    }

    /** Public API: hide a single layer (hard hide: display none + overlays) */
    hideLayer(layer, { soft = false } = {}) {
        if (!this.map || !layer) return;

        if (soft) {
            // keep on map, just non-interactive / invisible
            if (layer.setStyle) layer.setStyle({ opacity: 0, fillOpacity: 0 });
            else if (layer.setOpacity) layer.setOpacity(0);
            this.#hideOverlays(layer, true);
            layer.__hidden = true;
            return;
        }

        const el = this.#getLayerEl(layer);
        if (el) el.style.display = 'none';
        this.#hideOverlays(layer, true);
        layer.__hidden = true;

        // If Ember re-creates the layer later, keep it hidden on add:
        if (!layer.__hookedAdd) {
            layer.on?.('add', () => {
                if (!layer.__hidden) return;
                const el2 = this.#getLayerEl(layer);
                if (el2) el2.style.display = 'none';
                this.#hideOverlays(layer, false);
            });
            layer.__hookedAdd = true;
        }
    }

    /** Public API: show a single layer (reverse of hide) */
    showLayer(layer, { soft = false } = {}) {
        if (!this.map || !layer) return;

        if (soft) {
            if (layer.setStyle) {
                const base = { opacity: 1 };
                // leaflets default fillOpacity if fill:true is ~0.2
                const fillOpacity = layer.options?.fillOpacity ?? 0.2;
                const hasFillOpacity = !isNone(layer.options?.fill);
                base.fillOpacity = hasFillOpacity ? fillOpacity : 0;
                layer.setStyle(base);
            } else if (layer.setOpacity) {
                layer.setOpacity(1);
            }
            this.#showOverlays(layer);
            layer.__hidden = false;
            return;
        }

        const el = this.#getLayerEl(layer);
        if (el) el.style.display = '';
        this.#showOverlays(layer);
        layer.__hidden = false;
    }

    showModelLayer(model, opts) {
        this.showLayer(model?.leafletLayer, opts);
    }

    hideModelLayer(model, opts) {
        this.hideLayer(model?.leafletLayer, opts);
    }

    registerLayer(category, layer, { id, hidden = false } = {}) {
        if (!this.map || !layer) return;

        // keep registry (same as you had)
        if (!this.#byCategory.has(category)) this.#byCategory.set(category, new Set());
        this.#byCategory.get(category).add(layer);

        if (id != null) {
            this.#byId.set(`${category}:${id}`, layer);
            layer.record_category = category;
            layer.record_key = `${category}:${id}`;
        }

        if (hidden) this.hideLayer(layer);

        return layer;
    }

    // Resolve the pane element a layer lives in (respects custom panes)
    #getPaneForLayer(layer) {
        if (!this.map || !layer) return null;

        const paneName = layer?.options?.pane || layer?.options?.renderer?.options?.pane || null;

        if (paneName) {
            return this.map.getPane?.(paneName) || null;
        }

        // Fallback to default overlay pane
        return this.map.getPane?.('overlayPane') || null;
    }

    isLayerHidden(layer, { includePaneState = true } = {}) {
        if (!layer) return true;

        // Hidden by our API
        if (layer.__hidden) return true;

        // Hard-hidden via element
        const el = this.#getLayerEl(layer);
        if (el && el.style?.display === 'none') return true;

        // Category/pane hidden
        if (includePaneState) {
            const pane = this.#getPaneForLayer(layer);
            if (pane && pane.style?.display === 'none') return true;
        }

        return false;
    }

    isLayerVisible(layer, opts) {
        return !this.isLayerHidden(layer, opts);
    }

    isModelLayerHidden(model, opts) {
        return this.isLayerHidden(model?.leafletLayer, opts);
    }

    isModelLayerVisible(model, opts) {
        return this.isLayerVisible(model?.leafletLayer, opts);
    }

    isCategoryVisible(category) {
        if (!this.map) return false;

        const pane = this.map.getPane?.(`pane-${category}`);
        if (!pane) return false;

        return pane.style.display !== 'none';
    }

    isCategoryHidden(category) {
        return !this.isCategoryVisible(category);
    }
}
