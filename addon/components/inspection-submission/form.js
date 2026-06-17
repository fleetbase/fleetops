import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const STATUS_OPTIONS = ['draft', 'submitted', 'needs_review', 'resolved'];
const RESULT_OPTIONS = ['passed', 'failed'];
const SEVERITY_OPTIONS = ['low', 'medium', 'high', 'critical'];

export default class InspectionSubmissionFormComponent extends Component {
    statusOptions = STATUS_OPTIONS;
    resultOptions = RESULT_OPTIONS;
    severityOptions = SEVERITY_OPTIONS;

    @tracked itemResults = [];

    constructor(owner, args) {
        super(owner, args);
        this.itemResults = [...(args.resource?.item_results ?? [])];
        this.syncResults();
    }

    syncResults() {
        this.args.resource.item_results = this.itemResults;
        const failed = this.itemResults.filter((item) => item.passed === false).length;
        this.args.resource.total_items = this.itemResults.length;
        this.args.resource.failed_items = failed;
        this.args.resource.result = failed > 0 ? 'failed' : 'passed';
    }

    seedFromForm(form) {
        const items = form?.items ?? [];
        if (!items.length || this.itemResults.length) {
            return;
        }

        this.itemResults = items.map((item, index) => ({
            item_key: item.key || `item_${index + 1}`,
            label: item.label,
            category: item.category,
            severity: item.severity || 'medium',
            status: 'passed',
            passed: true,
            comments: '',
            photos: [],
        }));
        this.syncResults();
    }

    @action assignForm(form) {
        this.args.resource.form = form;
        this.args.resource.type = form?.type || this.args.resource.type || 'dvir';
        this.seedFromForm(form);
    }

    @action assignVehicle(vehicle) {
        this.args.resource.vehicle = vehicle;
    }

    @action assignDriver(driver) {
        this.args.resource.driver = driver;
    }

    @action addResult() {
        this.itemResults = [
            ...this.itemResults,
            {
                item_key: `custom_${this.itemResults.length + 1}`,
                label: '',
                category: '',
                severity: 'medium',
                status: 'passed',
                passed: true,
                comments: '',
            },
        ];
        this.syncResults();
    }

    @action removeResult(index) {
        this.itemResults = this.itemResults.filter((_, itemIndex) => itemIndex !== index);
        this.syncResults();
    }

    @action updateResult(index, key, value) {
        this.itemResults = this.itemResults.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            const next = { ...item, [key]: value };
            if (key === 'status') {
                next.passed = value !== 'failed';
            }
            if (key === 'passed') {
                next.status = value ? 'passed' : 'failed';
            }

            return next;
        });
        this.syncResults();
    }
}
