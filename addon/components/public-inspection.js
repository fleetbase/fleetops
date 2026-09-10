import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class PublicInspectionComponent extends Component {
    @service urlSearchParams;
    @service fetch;

    @tracked form = null;
    @tracked identity = null;
    @tracked itemResults = [];
    @tracked odometer = '';
    @tracked engineHours = '';
    @tracked signatureName = '';
    @tracked error = null;
    @tracked submission = null;

    constructor() {
        super(...arguments);
        this.loadInspection.perform();
    }

    get formId() {
        return this.urlSearchParams.get('id');
    }

    get token() {
        return this.urlSearchParams.get('token');
    }

    get failedCount() {
        return this.itemResults.filter((item) => item.passed === false).length;
    }

    get resultLabel() {
        return this.failedCount > 0 ? 'failed' : 'passed';
    }

    get canSubmit() {
        return this.form && this.itemResults.length > 0 && !this.submitInspection.isRunning && !this.submission;
    }

    @task({ restartable: true })
    *loadInspection() {
        this.error = null;

        if (!this.formId || !this.token) {
            this.error = 'Inspection link is missing a form or token.';
            return;
        }

        try {
            const response = yield this.fetch.get(`inspections/forms/${this.formId}`, { token: this.token }, { namespace: 'fleet-ops/public' });
            this.form = response?.form;
            this.identity = response?.identity;
            this.itemResults = this.checklistOf(this.form);
        } catch (error) {
            this.error = error?.payload?.error ?? error?.message ?? 'Unable to load inspection.';
        }
    }

    @task({ drop: true })
    *submitInspection() {
        this.error = null;

        try {
            const response = yield this.fetch.post(
                `inspections/forms/${this.formId}/submit`,
                {
                    token: this.token,
                    odometer: this.odometer ? parseInt(this.odometer, 10) : null,
                    engine_hours: this.engineHours ? parseInt(this.engineHours, 10) : null,
                    signature: this.signatureName ? { name: this.signatureName, signed_at: new Date().toISOString() } : null,
                    item_results: this.itemResults,
                },
                { namespace: 'fleet-ops/public' }
            );
            this.submission = response?.submission;
        } catch (error) {
            this.error = error?.payload?.error ?? error?.message ?? 'Unable to submit inspection.';
        }
    }

    /**
     * The checklist a tokenised link offers.
     *
     * A form built from fields answers `grouped_fields`, not `items`, so the
     * pass/fail fields among them are what the link asks about — keyed the way
     * the server keys a derived result (`field.name ?? field.id`), so a link
     * submission and an app submission name the same item. The other field
     * types are not offered here: a public link is a checklist handed to a
     * contractor with a phone number, not the app.
     */
    checklistOf(form) {
        const items = Array.isArray(form?.items) ? form.items : [];
        if (items.length) {
            return items.map((item, index) => ({
                item_key: item.key || `item_${index + 1}`,
                label: item.label,
                category: item.category,
                severity: item.severity || 'medium',
                status: 'passed',
                passed: true,
                comments: '',
                photos: [],
            }));
        }

        const groups = Array.isArray(form?.grouped_fields) ? form.grouped_fields : [];

        return groups.reduce((carry, group) => {
            const fields = Array.isArray(group?.fields) ? group.fields : [];

            return carry.concat(
                fields
                    .filter((field) => field?.type === 'pass-fail')
                    .map((field) => ({
                        item_key: field.name || field.id,
                        label: field.label,
                        category: group.name ?? null,
                        severity: field.meta?.severity || 'medium',
                        status: 'passed',
                        passed: true,
                        comments: '',
                        photos: [],
                    }))
            );
        }, []);
    }

    @action updateReading(key, event) {
        this[key] = event.target.value;
    }

    @action markItem(index, passed) {
        this.itemResults = this.itemResults.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            return {
                ...item,
                passed,
                status: passed ? 'passed' : 'failed',
            };
        });
    }

    @action updateComments(index, event) {
        const comments = event.target.value;
        this.itemResults = this.itemResults.map((item, itemIndex) => (itemIndex === index ? { ...item, comments } : item));
    }

    @action updatePhotos(index, event) {
        const photos = event.target.value
            .split('\n')
            .map((value) => value.trim())
            .filter(Boolean);
        this.itemResults = this.itemResults.map((item, itemIndex) => (itemIndex === index ? { ...item, photos } : item));
    }
}
