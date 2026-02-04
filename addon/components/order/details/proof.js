import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
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
        const proofs = yield this.fetch.get(`orders/${order.id}/proofs`);

        this.proofs = proofs.map((proof) => ({
            ...proof,
            type: this.#getTypeFromRemarks(proof.remarks),
        }));
    }

    #getTypeFromRemarks(remarks = '') {
        if (remarks.endsWith('Photo')) return 'photo';
        if (remarks.endsWith('Scan')) return 'scan';
        if (remarks.endsWith('Signature')) return 'signature';
        return undefined;
    }
}
