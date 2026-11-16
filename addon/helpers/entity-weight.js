import { helper } from '@ember/component/helper';

const read = (entity, key) => entity?.get?.(key) ?? entity?.[key];

/** An entity's weight with its unit, or the fallback when it has none. */
export default helper(function entityWeight([entity, fallback = '']) {
    const weight = read(entity, 'weight');
    if (weight === undefined || weight === null || weight === '') {
        return fallback;
    }
    return `${Number(weight).toLocaleString()} ${read(entity, 'weight_unit') ?? ''}`.trim();
});
