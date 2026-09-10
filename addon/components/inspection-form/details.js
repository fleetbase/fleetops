import Component from '@glimmer/component';
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
        next(() => this.load.perform());
    }

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
