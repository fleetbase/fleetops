import BaseLayer from 'ember-leaflet/components/base-layer';
import { computed } from '@ember/object';
import { assign } from '@ember/polyfills';
import { scheduleOnce } from '@ember/runloop';
import { classify, camelize } from '@ember/string';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

export default class LeafletDrawControl extends BaseLayer {
    leafletEvents = [
        L.Draw.Event.CREATED,
        L.Draw.Event.EDITED,
        L.Draw.Event.EDITMOVE,
        L.Draw.Event.EDITRESIZE,
        L.Draw.Event.EDITSTART,
        L.Draw.Event.EDITSTOP,
        L.Draw.Event.EDITVERTEX,
        L.Draw.Event.DELETED,
        L.Draw.Event.DELETESTART,
        L.Draw.Event.DELETESTOP,
        L.Draw.Event.DRAWSTART,
        L.Draw.Event.DRAWSTOP,
        L.Draw.Event.DRAWVERTEX,
    ];

    leafletOptions = ['draw', 'edit', 'remove', 'poly', 'position'];

    @computed('leafletEvents.[]', 'args') get usedLeafletEvents() {
        return this.leafletEvents.filter((eventName) => {
            eventName = camelize(eventName.replace(':', ' '));
            let methodName = `_${eventName}`;
            let actionName = `on${classify(eventName)}`;

            return this[methodName] !== undefined || this.args[actionName] !== undefined;
        });
    }

    @computed('args.{draw,edit,remove,poly,position}') get options() {
        return {
            position: getWithDefault(this.args, 'position', 'topright'),
            draw: getWithDefault(this.args, 'draw', { marker: false, circlemarker: false, circle: false, polyline: false }),
            edit: getWithDefault(this.args, 'edit', {}),
            remove: getWithDefault(this.args, 'remove', {}),
            poly: getWithDefault(this.args, 'poly', null),
        };
    }

    @computed('args.parent._layer') get map() {
        return this.args.parent._layer;
    }

    addToContainer() {
        if (this._layer) {
            this.map.addLayer(this._layer);
        }
    }

    createLayer() {
        const { onDrawFeatureGroupCreated } = this.args;
        const drawingLayerGroup = new this.L.FeatureGroup();
        const showDrawingLayer = getWithDefault(this.args, 'showDrawingLayer', true);

        if (showDrawingLayer) {
            if (typeof onDrawFeatureGroupCreated === 'function') {
                onDrawFeatureGroupCreated(drawingLayerGroup, this.map);
            }

            drawingLayerGroup.addTo(this.map);
        }

        return drawingLayerGroup;
    }

    didCreateLayer() {
        const { onDrawControlCreated, onDrawControlAddedToMap } = this.args;
        const showDrawingLayer = getWithDefault(this.args, 'showDrawingLayer', true);

        if (this.map && this._layer && this.L.drawLocal) {
            this.options.edit = assign({ featureGroup: this._layer }, this.options.edit);
            this.options.draw = assign({}, this.L.drawLocal.draw, this.options.draw);

            // create draw control
            const drawControl = new this.L.Control.Draw(this.options);

            // trigger action/event draw control created
            if (typeof onDrawControlCreated === 'function') {
                onDrawControlCreated(drawControl, this.map);
            }

            // Add the draw control to the map
            this.map.addControl(drawControl);

            // trigger action/event draw control added to map
            if (typeof onDrawControlAddedToMap === 'function') {
                onDrawControlAddedToMap(drawControl, this.map);
            }

            // If showDrawingLayer, add new layer to the layerGroup
            if (showDrawingLayer) {
                this.map.on(this.L.Draw.Event.CREATED, ({ layer }) => {
                    this._layer.addLayer(layer);
                });
            }
        }
    }

    _addEventListeners() {
        this._eventHandlers = {};

        for (let eventName of this.usedLeafletEvents) {
            const originalEventName = eventName;
            // fix event name
            eventName = camelize(eventName.replace(':', ' '));
            const actionName = `on${classify(eventName)}`;
            const methodName = `_${eventName}`;

            // create an event handler that runs the function inside an event loop.
            this._eventHandlers[originalEventName] = function (e) {
                let fn = () => {
                    // try to invoke/send an action for this event
                    if (typeof this.args[actionName] === 'function') {
                        this.args[actionName](e, this._layer, this.map);
                    }

                    // allow classes to add custom logic on events as well
                    if (typeof this[methodName] === 'function') {
                        this[methodName](e, this._layer, this.map);
                    }
                };

                scheduleOnce('actions', this, fn);
            };

            this.map.addEventListener(originalEventName, this._eventHandlers[originalEventName], this);
        }
    }

    _removeEventListeners() {
        if (this._eventHandlers) {
            for (let eventName of this.usedLeafletEvents) {
                this.map.removeEventListener(eventName, this._eventHandlers[eventName], this);
                delete this._eventHandlers[eventName];
            }
        }
    }
}
