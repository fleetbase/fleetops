import { isArray } from '@ember/array';
import normalizeOrderConfigFlow, { getOrderConfigFlowRootCode } from './normalize-order-config-flow';

/**
 * A config's steps in reading order: from the first step, each step followed by the steps it
 * leads to, breadth first. A step reached as a second (or later) way out of another is a branch,
 * and says which step it branches from. Steps nothing leads to come last.
 *
 * @returns {Array<{code, label, details, activity, next: Array<String>, branchFrom: ?String, index}>}
 */
export default function orderConfigSteps(config) {
    const flow = normalizeOrderConfigFlow(config?.flow ?? {});
    const nextOf = (activity) => (isArray(activity?.activities) ? activity.activities : []).map((next) => (typeof next === 'string' ? next : (next?.code ?? next?.key))).filter(Boolean);
    const root = getOrderConfigFlowRootCode(flow, config?.meta?.lifecycle?.initial);
    const ordered = [];
    const seen = new Set();
    const queue = root ? [{ code: root, branchFrom: null }] : [];

    while (queue.length) {
        const { code, branchFrom } = queue.shift();
        if (seen.has(code) || !flow[code]) {
            continue;
        }
        seen.add(code);
        ordered.push({ code, branchFrom });
        nextOf(flow[code]).forEach((next, index) => queue.push({ code: next, branchFrom: index > 0 ? code : null }));
    }
    Object.keys(flow).forEach((code) => {
        if (!seen.has(code)) {
            ordered.push({ code, branchFrom: null });
        }
    });

    return ordered.map(({ code, branchFrom }, index) => {
        const activity = flow[code];
        return { code, label: activity.status || code, details: activity.details, activity, next: nextOf(activity), branchFrom, index };
    });
}
