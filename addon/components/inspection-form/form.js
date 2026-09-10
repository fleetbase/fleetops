import Component from '@glimmer/component';
import { action } from '@ember/object';

const TYPE_OPTIONS = ['dvir', 'safety', 'compliance', 'maintenance', 'pre_trip', 'post_trip'];
const STATUS_OPTIONS = ['draft', 'published', 'archived'];
const FREQUENCY_OPTIONS = ['daily', 'weekly', 'monthly', 'pre_trip', 'post_trip', 'ad_hoc'];
const SEVERITY_OPTIONS = ['low', 'medium', 'high', 'critical'];

/**
 * Checklist editor for an inspection form.
 *
 * The model's `items` attribute is the single source of truth; the component
 * holds no copy of it. Every change builds a new array and assigns it to the
 * model from an action, never during render — the previous version wrote to
 * `@resource.items` in the constructor, which Glimmer refuses ("attempted to
 * update `items` ... already used in the same computation") because the
 * template had already read the attribute in the same render pass.
 */
export default class InspectionFormFormComponent extends Component {
    typeOptions = TYPE_OPTIONS;
    statusOptions = STATUS_OPTIONS;
    frequencyOptions = FREQUENCY_OPTIONS;
    severityOptions = SEVERITY_OPTIONS;

    get items() {
        const items = this.args.resource?.items;
        return Array.isArray(items) ? items : [];
    }

    setItems(items) {
        this.args.resource.items = items;
    }

    @action addItem() {
        const items = this.items;
        this.setItems([
            ...items,
            {
                key: `item_${items.length + 1}`,
                label: '',
                category: '',
                required: true,
                severity: 'medium',
            },
        ]);
    }

    @action removeItem(index) {
        this.setItems(this.items.filter((_, itemIndex) => itemIndex !== index));
    }

    /** For selects, which hand over the chosen value. */
    @action updateItem(index, key, value) {
        this.setItems(this.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [key]: value } : item)));
    }

    /** For text inputs, which hand over the DOM event. */
    @action updateItemField(index, key, event) {
        this.updateItem(index, key, event.target.value);
    }

    @action toggleItemRequired(index, event) {
        this.updateItem(index, 'required', event.target.checked);
    }

    @action setSetting(key, value) {
        this.args.resource.settings = {
            ...(this.args.resource.settings ?? {}),
            [key]: value,
        };
    }
}
