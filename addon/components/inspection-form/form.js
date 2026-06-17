import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const TYPE_OPTIONS = ['dvir', 'safety', 'compliance', 'maintenance', 'pre_trip', 'post_trip'];
const STATUS_OPTIONS = ['draft', 'published', 'archived'];
const FREQUENCY_OPTIONS = ['daily', 'weekly', 'monthly', 'pre_trip', 'post_trip', 'ad_hoc'];
const SEVERITY_OPTIONS = ['low', 'medium', 'high', 'critical'];

export default class InspectionFormFormComponent extends Component {
    typeOptions = TYPE_OPTIONS;
    statusOptions = STATUS_OPTIONS;
    frequencyOptions = FREQUENCY_OPTIONS;
    severityOptions = SEVERITY_OPTIONS;

    @tracked items = [];

    constructor(owner, args) {
        super(owner, args);
        this.items = [...(args.resource?.items ?? [])];
        this.syncItems();
    }

    syncItems() {
        this.args.resource.items = this.items;
    }

    @action addItem() {
        this.items = [
            ...this.items,
            {
                key: `item_${this.items.length + 1}`,
                label: '',
                category: '',
                required: true,
                severity: 'medium',
            },
        ];
        this.syncItems();
    }

    @action removeItem(index) {
        this.items = this.items.filter((_, itemIndex) => itemIndex !== index);
        this.syncItems();
    }

    @action updateItem(index, key, value) {
        this.items = this.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [key]: value } : item));
        this.syncItems();
    }

    @action setSetting(key, value) {
        this.args.resource.settings = {
            ...(this.args.resource.settings ?? {}),
            [key]: value,
        };
    }
}
