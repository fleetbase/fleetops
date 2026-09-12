import Component from '@glimmer/component';
import { INSPECTION_SEVERITIES } from '../../utils/inspection-field-types';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';

/**
 * An inspection form, read-only: what it is, and the groups of fields it is
 * built from, in the order a driver answers them.
 */
export default class InspectionFormDetailsComponent extends Component {
    @service inspectionFormActions;

    @tracked groups = [];

    constructor() {
        super(...arguments);
        next(() => {
            if (this.isDestroying || this.isDestroyed) {
                return;
            }

            this.load.perform();
        });
    }

    /**
     * The four severities that have a label of their own, as a lookup. A field
     * converted from a hand-written first-cut item can carry anything, and
     * asking for a translation of that would put "Missing translation" on the
     * screen.
     */
    severityLabels = INSPECTION_SEVERITIES.reduce((carry, severity) => ({ ...carry, [severity]: `inspection.severity.${severity}` }), {});

    get fieldCount() {
        return this.groups.reduce((count, group) => count + (group.fields?.length ?? 0), 0);
    }

    get legacyItems() {
        const items = this.args.resource?.items;
        return Array.isArray(items) ? items : [];
    }

    @task *load() {
        if (!this.args.resource?.id) {
            return;
        }

        try {
            this.groups = yield this.inspectionFormActions.loadStructure(this.args.resource);
        } catch (error) {
            debug('Unable to load inspection form structure: ' + error.message);
        }
    }
}
