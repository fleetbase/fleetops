import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { createField, createFieldGroup } from '../../utils/inspection-form-structure';

/**
 * The form builder: field groups, each with a grid size and its own typed
 * fields.
 *
 * A form is laid out before the form record exists, so the builder holds the
 * whole structure as a draft of plain objects and hands it up through
 * `@onChange`; the controller posts it with the save that creates or updates
 * the form, and `InspectionFormSync` writes it in one go. Plain objects rather
 * than Ember Data records because the `inspection-form` model belongs to
 * `@fleetbase/fleetops-data` and declares no structure attribute.
 *
 * Nothing here mutates a group or a field in place. Every change builds new
 * objects and assigns them from an action — never during render.
 */
export default class InspectionFormBuilderComponent extends Component {
    @service inspectionFormActions;
    @service modalsManager;
    @service notifications;
    @service intl;

    @tracked groups = [];

    gridSizeOptions = [1, 2, 3];

    constructor() {
        super(...arguments);
        next(() => {
            if (this.isDestroying || this.isDestroyed) {
                return;
            }

            this.load.perform();
        });
    }

    get isDraft() {
        const resource = this.args.resource;
        return !resource?.id || resource?.isNew === true;
    }

    @task *load() {
        if (this.isDraft) {
            return;
        }

        try {
            this.groups = yield this.inspectionFormActions.loadStructure(this.args.resource);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** The one place the draft is written, and the one place it is announced. */
    write(groups) {
        this.groups = groups;

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(groups);
        }
    }

    replaceGroup(uuid, attributes) {
        this.write(this.groups.map((group) => (group.uuid === uuid ? { ...group, ...attributes } : group)));
    }

    @action addGroup() {
        this.write([...this.groups, createFieldGroup({ name: this.intl.t('inspection.builder.untitled-group'), order: this.groups.length + 1 })]);
    }

    @action setGroupName(uuid, event) {
        this.replaceGroup(uuid, { name: event.target.value });
    }

    @action setGroupDescription(uuid, event) {
        this.replaceGroup(uuid, { description: event.target.value });
    }

    @action setGridSize(group, size) {
        this.replaceGroup(group.uuid, { meta: { ...(group.meta ?? {}), grid_size: size } });
    }

    @action moveGroup(index, offset) {
        const target = index + offset;
        if (target < 0 || target >= this.groups.length) {
            return;
        }

        const groups = [...this.groups];
        const [moved] = groups.splice(index, 1);
        groups.splice(target, 0, moved);
        this.write(groups);
    }

    @action deleteGroup(group) {
        this.modalsManager.confirm({
            title: this.intl.t('inspection.builder.delete-group-title'),
            body: this.intl.t('inspection.builder.delete-group-body'),
            acceptButtonText: this.intl.t('inspection.builder.delete'),
            acceptButtonType: 'danger',
            confirm: (modal) => {
                this.write(this.groups.filter((candidate) => candidate.uuid !== group.uuid));
                modal.done();
            },
        });
    }

    @action addField(group) {
        this.editField(group, createField('pass-fail', { label: this.intl.t('inspection.builder.untitled-field'), order: (group.fields?.length ?? 0) + 1 }), true);
    }

    @action editField(group, field, isNew = false) {
        const state = { field };

        this.modalsManager.show('modals/inspection-field', {
            title: isNew ? this.intl.t('inspection.builder.new-field') : this.intl.t('inspection.builder.edit-field', { label: field.label }),
            acceptButtonText: this.intl.t('inspection.builder.save-field'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            state,
            disabled: this.args.disabled,
            confirm: (modal) => {
                this.applyField(group, state.field, isNew);
                modal.done();
            },
        });
    }

    applyField(group, field, isNew) {
        const fields = group.fields ?? [];
        const nextFields = isNew ? [...fields, field] : fields.map((candidate) => (candidate.uuid === field.uuid ? field : candidate));

        this.replaceGroup(group.uuid, { fields: nextFields });
    }

    @action moveField(group, index, offset) {
        const fields = [...(group.fields ?? [])];
        const target = index + offset;
        if (target < 0 || target >= fields.length) {
            return;
        }

        const [moved] = fields.splice(index, 1);
        fields.splice(target, 0, moved);
        this.replaceGroup(group.uuid, { fields });
    }

    @action deleteField(group, field) {
        this.modalsManager.confirm({
            title: this.intl.t('inspection.builder.delete-field-title'),
            body: this.intl.t('inspection.builder.delete-field-body'),
            acceptButtonText: this.intl.t('inspection.builder.delete'),
            acceptButtonType: 'danger',
            confirm: (modal) => {
                this.replaceGroup(group.uuid, { fields: (group.fields ?? []).filter((candidate) => candidate.uuid !== field.uuid) });
                modal.done();
            },
        });
    }
}
