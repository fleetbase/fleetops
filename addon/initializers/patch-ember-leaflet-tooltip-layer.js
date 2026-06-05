import TooltipLayer from 'ember-leaflet/components/tooltip-layer';

function hasMethod(object, methodName) {
    return typeof object?.[methodName] === 'function';
}

export function initialize() {
    const prototype = TooltipLayer?.prototype;
    if (!prototype || prototype.__fleetopsTooltipLayerPatched) {
        return;
    }

    const addToContainer = prototype.addToContainer;
    const removeFromContainer = prototype.removeFromContainer;
    const removePopupListeners = prototype._removePopupListeners;

    prototype.addToContainer = function () {
        const parentLayer = this.args?.parent?._layer;
        if (!this._layer || !hasMethod(parentLayer, 'bindTooltip')) {
            return;
        }

        return addToContainer.call(this);
    };

    prototype.removeFromContainer = function () {
        const parentLayer = this.args?.parent?._layer;
        if (!hasMethod(parentLayer, 'unbindTooltip')) {
            return;
        }

        return removeFromContainer.call(this);
    };

    prototype._removePopupListeners = function () {
        const parentMap = this.args?.parent?._layer?._map;
        if (!hasMethod(parentMap, 'removeEventListener')) {
            return;
        }

        return removePopupListeners.call(this);
    };

    Object.defineProperty(prototype, '__fleetopsTooltipLayerPatched', {
        value: true,
    });
}

export default {
    initialize,
};
