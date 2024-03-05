import ObjectProxy from '@ember/object/proxy';

export default function createCustomEntity(name = '', type = '', description = '', props = {}) {
    return ObjectProxy.create({
        content: {
            name,
            description,
            type,
            dimensions_unit: 'cm',
            weight_unit: 'kg',
            ...props,
            _internalModel: {
                modelName: 'custom-entity',
            },
        },
    });
}
