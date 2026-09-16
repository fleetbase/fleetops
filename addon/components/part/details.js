import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

/**
 * A part only knows the asset it is fitted to by type and uuid; there is no
 * relation on the model. Look it up so the field can show the asset's pill,
 * with asset_name as the text meanwhile.
 */
export default class PartDetailsComponent extends Component {
    @service store;
    @service resourceRegistry;
    @tracked fittedTo = null;

    constructor() {
        super(...arguments);
        this.loadFittedTo.perform();
    }

    @task *loadFittedTo() {
        const part = this.args.resource;
        const id = part?.asset_uuid;
        const key = this.resourceRegistry?.resolveKey?.(part?.asset_type);

        if (!id || !key) {
            this.fittedTo = null;
            return;
        }

        try {
            this.fittedTo = this.store.peekRecord(key, id) ?? (yield this.store.findRecord(key, id));
        } catch (error) {
            debug(`Could not load the asset part ${part?.id} is fitted to: ${error.message}`);
            this.fittedTo = null;
        }
    }
}
