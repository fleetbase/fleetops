import { helper } from '@ember/component/helper';

const read = (entity, key) => entity?.get?.(key) ?? entity?.[key];

/** An entity's outside size as "L × W × H unit", or the fallback when any is missing. */
export default helper(function entitySize([entity, fallback = '']) {
    const values = ['length', 'width', 'height'].map((key) => read(entity, key));
    if (values.some((value) => value === undefined || value === null || value === '')) {
        return fallback;
    }
    return `${values.join(' × ')} ${read(entity, 'dimensions_unit') ?? ''}`.trim();
});
