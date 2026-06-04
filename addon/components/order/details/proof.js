import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency-decorators';

export default class OrderDetailsProofComponent extends Component {
    @service fetch;
    @tracked proofs = [];

    constructor(owner, { resource }) {
        super(...arguments);
        this.loadOrderProofs.perform(resource);
    }

    @task *loadOrderProofs(order) {
        if (!order?.id) {
            this.proofs = [];
            return;
        }

        const proofs = yield this.fetch.get(`orders/${order.id}/proofs`);

        this.proofs = this.#normalizeProofs(proofs);
    }

    @action reloadProofs() {
        this.loadOrderProofs.perform(this.args.resource);
    }

    #getTypeFromRemarks(remarks = '') {
        if (remarks.endsWith('Photo')) return 'photo';
        if (remarks.endsWith('Scan')) return 'scan';
        if (remarks.endsWith('Signature')) return 'signature';
        return undefined;
    }

    #normalizeProofs(proofs = []) {
        const seen = new Set();

        return proofs
            .filter((proof) => {
                const key = proof.uuid ?? proof.public_id ?? proof.id;
                if (!key || seen.has(key)) {
                    return false;
                }

                seen.add(key);
                return true;
            })
            .map((proof) => ({
                ...proof,
                type: this.#getTypeFromRemarks(proof.remarks),
            }))
            .sort((a, b) => new Date(b.created_at ?? 0).getTime() - new Date(a.created_at ?? 0).getTime());
    }
}
