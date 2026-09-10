import Component from '@glimmer/component';
import { action } from '@ember/object';

const TYPE_OPTIONS = ['dvir', 'safety', 'compliance', 'maintenance', 'pre_trip', 'post_trip'];
const STATUS_OPTIONS = ['draft', 'published', 'archived'];
const FREQUENCY_OPTIONS = ['daily', 'weekly', 'monthly', 'pre_trip', 'post_trip', 'ad_hoc'];

/**
 * The inspection form screen: what the form is, and what it is built from.
 *
 * The structure itself belongs to `inspection-form/builder`, which holds it as
 * a draft so a form can be laid out before the record exists; this component
 * only passes that draft up to the controller, which posts it with the save.
 *
 * Nothing writes to `@resource` during render — text inputs update from the
 * DOM event, and every other change arrives from an action.
 */
export default class InspectionFormFormComponent extends Component {
    typeOptions = TYPE_OPTIONS;
    statusOptions = STATUS_OPTIONS;
    frequencyOptions = FREQUENCY_OPTIONS;

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

    @action setType(type) {
        this.args.resource.type = type;
    }

    @action setStatus(status) {
        this.args.resource.status = status;
    }

    @action setFrequency(frequency) {
        this.args.resource.frequency = frequency;
    }

    @action setStructure(groups) {
        if (typeof this.args.onStructureChange === 'function') {
            this.args.onStructureChange(groups);
        }
    }

    @action setSettings(settings) {
        this.args.resource.settings = settings;
    }
}
