import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * The inspection form screen: what the form is, and what it is built from.
 *
 * The structure itself belongs to `inspection-form/builder`, which holds it as
 * a draft so a form can be laid out before the record exists; this component
 * only passes that draft up to the controller, which posts it with the save.
 *
 * Nothing writes to `@resource` during render — text inputs update from the
 * DOM event, and every other change arrives from an action.
 *
 * `frequency` is deliberately not offered. The column exists and the API still
 * carries it, but nothing schedules an inspection from it, so a dropdown here
 * would ask an author to answer a question the product does not yet act on.
 */
export default class InspectionFormFormComponent extends Component {
    @service intl;

    /**
     * The three switches a form actually has. Two are read by the server when
     * a submission has failures (`InspectionSubmitter`); the third is read by
     * the driver app before it will let a driver submit.
     */
    get settingOptions() {
        return [
            {
                key: 'create_issue_on_failure',
                label: this.intl.t('inspection.form.setting-create-issue'),
                description: this.intl.t('inspection.form.setting-create-issue-help'),
            },
            {
                key: 'create_work_order_on_failure',
                label: this.intl.t('inspection.form.setting-create-work-order'),
                description: this.intl.t('inspection.form.setting-create-work-order-help'),
            },
            {
                key: 'require_signature',
                label: this.intl.t('inspection.form.setting-require-signature'),
                description: this.intl.t('inspection.form.setting-require-signature-help'),
            },
        ];
    }

    get settings() {
        const settings = this.args.resource?.settings;
        return settings && typeof settings === 'object' ? settings : {};
    }

    /** The first cut's checklist, kept read-only until it has been migrated. */
    get legacyItems() {
        const items = this.args.resource?.items;
        return Array.isArray(items) ? items : [];
    }

    @action setName(event) {
        this.args.resource.name = event.target.value;
    }

    @action setDescription(event) {
        this.args.resource.description = event.target.value;
    }

    @action setType(option) {
        this.args.resource.type = option?.value ?? null;
    }

    @action setStatus(option) {
        this.args.resource.status = option?.value ?? null;
    }

    @action setSetting(key, event) {
        this.args.resource.settings = { ...this.settings, [key]: event.target.checked };
    }

    @action setStructure(groups) {
        if (typeof this.args.onStructureChange === 'function') {
            this.args.onStructureChange(groups);
        }
    }
}
