import { isArray } from '@ember/array';

export function getOrderConfigFlowRootCode(flow = {}) {
    if (flow.created) {
        return 'created';
    }

    return Object.keys(flow)[0];
}

export default function normalizeOrderConfigFlow(flow = {}) {
    if (isArray(flow)) {
        return flow.reduce((normalized, activity, index) => {
            if (!activity || typeof activity !== 'object') {
                return normalized;
            }

            const code = activity.code ?? activity.key;
            if (!code) {
                return normalized;
            }

            const nextActivity = flow.slice(index + 1).find((candidate) => candidate && typeof candidate === 'object' && (candidate.code ?? candidate.key));

            normalized[code] = {
                ...activity,
                code,
                key: activity.key ?? code,
                activities: isArray(activity.activities) ? activity.activities : nextActivity ? [nextActivity.code ?? nextActivity.key] : [],
            };

            return normalized;
        }, {});
    }

    if (flow && typeof flow === 'object') {
        return flow;
    }

    return {};
}
