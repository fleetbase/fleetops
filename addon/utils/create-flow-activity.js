import ObjectProxy from '@ember/object/proxy';
import generateUUID from '@fleetbase/ember-core/utils/generate-uuid';
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
            require_pod: false,
            pod_method: 'scan',
            require_inspection: false,
            inspection_form: null,
            inspection_form_name: null,
            options: {},
            node: null,
            internalId: generateUUID(),
            _internalModel: {
                modelName: 'activity',
            },
            ...props,
        },
    });
}
