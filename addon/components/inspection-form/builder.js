import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import inlineTask from '@fleetbase/ember-core/utils/inline-task';
import { createField, createFieldGroup } from '../../utils/inspection-form-structure';

/**
 * The form builder: field groups, each with a grid size and its own typed
 * fields.
 *
 * A form is laid out before the form record exists, so the structure is a
 * draft of plain objects rather than Ember Data records — the `inspection-form`
 * model belongs to `@fleetbase/fleetops-data` and declares no attribute for it.
 * The controller posts it with the save, and `InspectionFormSync` writes it in
 * one go.
 *
 * **The draft lives on the controller, not here.** `ContentPanel` unrenders its
 * body when it is collapsed, so this component is destroyed and rebuilt every
 * time the author folds the builder away; state held here went with it and the
 * form came back empty. So the component is controlled: it renders `@groups`
 * and reports every change through `@onChange`, and owns nothing that a
 * collapse can take.
 *
 * Nothing here mutates a group or a field in place. Every change builds new
 * objects and assigns them from an action — never during render.
 */
export default class InspectionFormBuilderComponent extends Component {
    @service inspectionFormActions;
    @service modalsManager;
    @service resourceContextPanel;
    @service notifications;
    @service intl;

    gridSizeOptions = [1, 2, 3];

    /** The draft, owned by the controller so it survives a panel collapse. */
    get groups() {
        return Array.isArray(this.args.groups) ? this.args.groups : [];
    }

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
        // A form that does not exist yet has nothing to read back.
        if (this.isDraft) {
            return;
        }

        // Already held by the controller — either loaded once before, or
        // carrying edits the author has not saved. Re-reading here would
        // throw those away every time the panel was reopened.
        if (Array.isArray(this.args.groups)) {
            return;
        }

        try {
            this.write(yield this.inspectionFormActions.loadStructure(this.args.resource));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** The one place the draft is announced. The controller stores it. */
    write(groups) {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(groups);
        }
    }

    replaceGroup(uuid, attributes) {
        this.write(this.groups.map((group) => (group.uuid === uuid ? { ...group, ...attributes } : group)));
    }

    /**
     * Paints an input's starting value without binding it.
     *
     * The iteration is keyed, so the node survives an edit — but a bound
     * `value` is rewritten on every render, and assigning to `value` mid-word
     * moves the caret to the end. Setting it once on insert leaves the DOM to
     * own the text, and `input` reports each change back.
     */
    @action setInitialValue(value, element) {
        element.value = value ?? '';
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

    /**
     * The field editor opens as a right-side overlay over the form's own
     * panel rather than as a modal: a modal covers the form the author is
     * building, and the two are read together. `xs` keeps it narrower than
     * the form panel behind it, so the form stays visible alongside.
     *
     * The field is a plain object in the builder's draft, so there is nothing
     * for the panel's default save to persist — `state` is the handle both
     * sides hold, and the inline task applies whatever the editor last
     * produced when the author saves.
     */
    @action editField(group, field, isNew = false) {
        const state = { field };

        this.resourceContextPanel.open({
            content: 'inspection-field/form',
            title: isNew ? this.intl.t('inspection.builder.new-field') : this.intl.t('inspection.builder.edit-field', { label: field.label }),
            size: 'xs',
            panelContentClass: 'py-2 px-4',
            state,
            disabled: this.args.disabled,
            saveTask: inlineTask((resource, { overlay } = {}) => {
                this.applyField(group, state.field, isNew);
                this.resourceContextPanel.close(overlay?.id);
            }),
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
