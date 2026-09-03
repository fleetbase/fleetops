import Service from '@ember/service';

/**
 * Base class for dummy-app stub services that need an Evented-like surface (`on`, `off`,
 * `trigger`) without pulling in the Ember Evented mixin.
 */
export default class StubEventedService extends Service {
    _listeners = new Map();

    on(eventName, callback) {
        if (!this._listeners.has(eventName)) {
            this._listeners.set(eventName, []);
        }
        this._listeners.get(eventName).push(callback);
        return this;
    }

    off(eventName, callback) {
        const listeners = this._listeners.get(eventName) ?? [];
        const index = listeners.indexOf(callback);
        if (index > -1) {
            listeners.splice(index, 1);
        }
        return this;
    }

    trigger(eventName, ...args) {
        const listeners = [...(this._listeners.get(eventName) ?? [])];
        for (const listener of listeners) {
            listener(...args);
        }
        return this;
    }
}
