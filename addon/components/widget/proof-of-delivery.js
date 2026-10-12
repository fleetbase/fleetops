import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { task } from 'ember-concurrency';
import { formatDistanceToNowStrict, isValid } from 'date-fns';
import config from '../../config/environment';

/**
 * The latest proof of delivery photos across the fleet, each linking to its order.
 */
export default class WidgetProofOfDeliveryComponent extends Component {
    static widgetId = 'fleet-ops-proof-of-delivery-widget';
    static limit = 12;

    @service fetch;

    @tracked proofs = [];
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get photos() {
        return (this.proofs ?? [])
            .filter((proof) => proof?.url)
            .map((proof) => ({
                ...proof,
                timeAgo: this.timeAgo(proof.captured_at),
            }));
    }

    get orderRoute() {
        // Dashboard widgets render under the host owner, outside the engine's routing context.
        return getOwner(this).mountPoint ? 'operations.orders.index.details' : `${config.mountedEngineRoutePrefix}.operations.orders.index.details`;
    }

    timeAgo(capturedAt) {
        const date = capturedAt ? new Date(capturedAt) : null;

        return date && isValid(date) ? formatDistanceToNowStrict(date, { addSuffix: true }) : null;
    }

    @action openPhoto(proof) {
        if (proof?.url) {
            window.open(proof.url, '_blank', 'noopener,noreferrer');
        }
    }

    @task({ restartable: true }) *load() {
        this.error = null;
        try {
            const response = yield this.fetch.get('fleet-ops/proofs/recent', { limit: WidgetProofOfDeliveryComponent.limit });
            this.proofs = response?.proofs ?? [];
        } catch (err) {
            this.error = err?.message ?? 'Failed to load';
        }
    }
}
