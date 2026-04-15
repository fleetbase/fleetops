import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class PlaceFormComponent extends Component {
    @tracked coordinatesInput;

    /**
     * Raw JSON string state for structured meta editors.
     * We track the raw textarea buffer separately from the parsed meta sub-key
     * so that users can make transiently invalid edits without us wiping meta.
     */
    @tracked operatingHoursRaw;
    @tracked dockInfoRaw;

    /** parse error flags (truthy => invalid JSON) */
    @tracked operatingHoursError;
    @tracked dockInfoError;

    constructor() {
        super(...arguments);
        const meta = this.args.resource?.meta ?? {};
        this.operatingHoursRaw = meta.operating_hours ? JSON.stringify(meta.operating_hours, null, 2) : '';
        this.dockInfoRaw = meta.dock_info ? JSON.stringify(meta.dock_info, null, 2) : '';
    }

    get specialInstructions() {
        return this.args.resource?.meta?.special_instructions ?? '';
    }

    @action onAutocomplete(selected) {
        this.args.resource.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }

    /**
     * Spread-update meta.<key> preserving all other meta keys.
     * When value is null/empty, the sub-key is omitted (not set to null)
     * to mirror Task 7's PHP helper null-removal semantics.
     */
    _writeMetaKey(key, value) {
        const currentMeta = { ...(this.args.resource.meta ?? {}) };
        if (value === null || value === undefined || value === '') {
            delete currentMeta[key];
        } else {
            currentMeta[key] = value;
        }
        // Re-assign the whole object so Ember Data sees the attr as dirty.
        this.args.resource.meta = currentMeta;
    }

    @action updateOperatingHours(event) {
        const raw = event?.target?.value ?? '';
        this.operatingHoursRaw = raw;
        const trimmed = raw.trim();
        if (trimmed === '') {
            this.operatingHoursError = null;
            this._writeMetaKey('operating_hours', null);
            return;
        }
        try {
            const parsed = JSON.parse(trimmed);
            this.operatingHoursError = null;
            this._writeMetaKey('operating_hours', parsed);
        } catch (e) {
            this.operatingHoursError = e.message;
            // do NOT write invalid JSON to meta — leave previous value intact
        }
    }

    @action updateDockInfo(event) {
        const raw = event?.target?.value ?? '';
        this.dockInfoRaw = raw;
        const trimmed = raw.trim();
        if (trimmed === '') {
            this.dockInfoError = null;
            this._writeMetaKey('dock_info', null);
            return;
        }
        try {
            const parsed = JSON.parse(trimmed);
            this.dockInfoError = null;
            this._writeMetaKey('dock_info', parsed);
        } catch (e) {
            this.dockInfoError = e.message;
        }
    }

    @action updateSpecialInstructions(event) {
        const value = event?.target?.value ?? '';
        this._writeMetaKey('special_instructions', value === '' ? null : value);
    }
}
