import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

/**
 * Inline hint shown while creating a driver, contact or customer profile:
 * tells the user when the entered email/phone belongs to an existing team
 * member (the profile will be linked to that account) or cannot be used.
 *
 * Args: `@email`, `@phone`, `@type` ('driver' | 'customer' | 'contact'),
 * `@ignore` (optional profile id).
 */
export default class ProfileIdentityHintComponent extends Component {
    @service fetch;
    @tracked staff = null;
    @tracked conflict = null;

    @action lookupIdentity() {
        this.lookup.perform();
    }

    @task({ restartable: true }) *lookup() {
        const { email, phone, type, ignore } = this.args;

        if (!email && !phone) {
            this.staff = null;
            this.conflict = null;
            return;
        }

        yield timeout(400);

        const query = { type };
        if (email) query.email = email;
        if (phone) query.phone = phone;
        if (ignore) query.ignore = ignore;

        try {
            const response = yield this.fetch.get('fleet-ops/lookup/profile-identity', query, { namespace: 'int/v1' });
            this.staff = response?.staff ?? null;
            this.conflict = response?.conflict ?? null;
        } catch {
            this.staff = null;
            this.conflict = null;
        }
    }
}
