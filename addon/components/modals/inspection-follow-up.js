import Component from '@glimmer/component';

/**
 * What an action is about to do to an inspection, shown before it happens.
 *
 * Creating an issue, creating a work order and resolving all used to fire on
 * click: the first a dispatcher knew of it was a toast and a new id. Each one
 * now states what it will make, from which failed items, and — where it
 * matters — what else it does on the way.
 */
export default class ModalsInspectionFollowUpComponent extends Component {
    get submission() {
        return this.args.options.submission;
    }

    /** The failures the follow-up is built from, worst first. */
    get failures() {
        const order = { critical: 0, high: 1, medium: 2, low: 3 };
        const results = this.submission?.item_results ?? [];

        return results
            .filter((item) => item.passed === false)
            .slice()
            .sort((a, b) => (order[a.severity] ?? 9) - (order[b.severity] ?? 9));
    }

    get unsafeCount() {
        return this.failures.filter((item) => item.meta?.unsafe || item.severity === 'critical').length;
    }

    get highestSeverity() {
        return this.failures[0]?.severity ?? null;
    }

    /** Nothing failed, so there is nothing to raise: the server would say so after the fact. */
    get hasNothingToDo() {
        return this.args.options.kind !== 'resolve' && this.failures.length === 0;
    }
}
