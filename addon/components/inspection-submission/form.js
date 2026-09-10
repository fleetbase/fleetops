import Component from '@glimmer/component';
import { action } from '@ember/object';

const STATUS_OPTIONS = ['draft', 'submitted', 'needs_review', 'resolved'];
const RESULT_OPTIONS = ['passed', 'failed'];
const SEVERITY_OPTIONS = ['low', 'medium', 'high', 'critical'];

/**
 * Checklist results editor for a console-authored inspection.
 *
 * `@resource.item_results` is the single source of truth. Counts and the
 * result are derived for display and written to the model only when the
 * results change from an action — never in the constructor, which is what
 * tripped Glimmer's "already used in the same computation" assertion.
 */
export default class InspectionSubmissionFormComponent extends Component {
    statusOptions = STATUS_OPTIONS;
    resultOptions = RESULT_OPTIONS;
    severityOptions = SEVERITY_OPTIONS;

    get itemResults() {
        const results = this.args.resource?.item_results;
        return Array.isArray(results) ? results : [];
    }

    get failedCount() {
        return this.itemResults.filter((item) => item.passed === false).length;
    }

    get totalCount() {
        return this.itemResults.length;
    }

    setResults(results) {
        const failed = results.filter((item) => item.passed === false).length;
        this.args.resource.item_results = results;
        this.args.resource.total_items = results.length;
        this.args.resource.failed_items = failed;
        this.args.resource.result = failed > 0 ? 'failed' : 'passed';
    }

    seedFromForm(form) {
        const items = form?.items ?? [];
        if (!items.length || this.itemResults.length) {
            return;
        }

        this.setResults(
            items.map((item, index) => ({
                item_key: item.key || `item_${index + 1}`,
                label: item.label,
                category: item.category,
                severity: item.severity || 'medium',
                status: 'passed',
                passed: true,
                comments: '',
                photos: [],
            }))
        );
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
        const results = this.itemResults;
        this.setResults([
            ...results,
            {
                item_key: `custom_${results.length + 1}`,
                label: '',
                category: '',
                severity: 'medium',
                status: 'passed',
                passed: true,
                comments: '',
                photos: [],
            },
        ]);
    }

    @action removeResult(index) {
        this.setResults(this.itemResults.filter((_, itemIndex) => itemIndex !== index));
    }

    /** For selects, which hand over the chosen value. */
    @action updateResult(index, key, value) {
        this.setResults(
            this.itemResults.map((item, itemIndex) => {
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
            })
        );
    }

    /** For text inputs, which hand over the DOM event. */
    @action updateResultField(index, key, event) {
        this.updateResult(index, key, event.target.value);
    }
}
