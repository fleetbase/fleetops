import ObjectProxy from '@ember/object/proxy';
import { underscore } from '@ember/string';

export default function createFlowActivity(name = '', status = '', details = '', sequence = 0, color = '#1f2937', props = {}) {
    return ObjectProxy.create({
        content: {
            code: underscore(name),
            key: underscore(name),
            status,
            details,
            sequence,
            color,
            activities: [],
            events: [],
            logic: [],
            actions: [],
            entities: [],
            complete: false,
            options: {},
            node: null,
            _internalModel: {
                modelName: 'activity',
            },
            ...props,
        },
    });
}
