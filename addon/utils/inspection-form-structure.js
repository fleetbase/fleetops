import generateUuid from '@fleetbase/ember-core/utils/generate-uuid';
import isObject from '@fleetbase/ember-core/utils/is-object';
import { componentForFieldType, INSPECTION_FIELD_TYPES } from './inspection-field-types';

/**
 * A form's structure, in the one shape the console holds it in.
 *
 * The `inspection-form` model belongs to `@fleetbase/fleetops-data` and
 * declares no structure attribute, so the builder cannot hang the groups off
 * the record and let Ember Data carry them. It reads the structure from the
 * internal form payload and writes it back whole under
 * `inspection_form.field_groups`, which is what
 * `InspectionFormController::syncStructureFromRequest()` looks for and what
 * `InspectionFormSync::sync()` matches on `uuid`.
 *
 * Everything here is plain objects. Nothing in this file mutates its argument.
 */

/** Sorts groups or fields the way the builder laid them out. */
function byOrder(a, b) {
    const ao = a?.order ?? Number.MAX_SAFE_INTEGER;
    const bo = b?.order ?? Number.MAX_SAFE_INTEGER;
    return ao - bo;
}

function metaOf(value) {
    return isObject(value) ? { ...value } : {};
}

/** One field, as the builder and the answering screen both read it. */
export function normalizeField(field, index = 0) {
    const type = INSPECTION_FIELD_TYPES.includes(field?.type) ? field.type : 'input';

    return {
        uuid: field?.uuid ?? field?.id ?? generateUuid(),
        name: field?.name ?? '',
        label: field?.label ?? '',
        description: field?.description ?? null,
        help_text: field?.help_text ?? null,
        type,
        component: field?.component ?? componentForFieldType(type),
        required: Boolean(field?.required),
        editable: field?.editable === undefined ? true : Boolean(field.editable),
        options: Array.isArray(field?.options) ? [...field.options] : [],
        order: field?.order ?? index + 1,
        meta: metaOf(field?.meta),
    };
}

/** One group, with its fields inside it and sorted. */
export function normalizeGroup(group, index = 0, fields = []) {
    const own = Array.isArray(group?.fields) ? group.fields : Array.isArray(group?.customFields) ? group.customFields : fields;

    return {
        uuid: group?.uuid ?? group?.id ?? generateUuid(),
        name: group?.name ?? '',
        description: group?.description ?? null,
        order: group?.order ?? index + 1,
        meta: { grid_size: 1, ...metaOf(group?.meta) },
        fields: [...own].sort(byOrder).map((field, fieldIndex) => normalizeField(field, fieldIndex)),
    };
}

/**
 * The structure held in an internal form payload.
 *
 * A read carries `field_groups` (the groups alone) beside a flat `fields` list
 * that names its group by `category_uuid`; `grouped_fields` carries the same
 * thing already nested, and is what the driver API answers with. Either is
 * accepted, so this works against a record read through the console and one
 * read through the public link.
 */
export function normalizeFieldGroups(payload) {
    if (!payload) {
        return [];
    }

    const groups = Array.isArray(payload.field_groups) ? payload.field_groups : [];
    const fields = Array.isArray(payload.fields) ? payload.fields : [];

    if (groups.length) {
        return groups
            .slice()
            .sort(byOrder)
            .map((group, index) => {
                const groupUuid = group?.uuid ?? group?.id;
                const own = fields.filter((field) => (field?.category_uuid ?? null) === groupUuid);
                return normalizeGroup(group, index, own);
            });
    }

    const grouped = Array.isArray(payload.grouped_fields) ? payload.grouped_fields : [];

    return grouped
        .slice()
        .sort(byOrder)
        .map((group, index) => normalizeGroup(group, index));
}

/** Every field of every group, flattened, in the order they are answered. */
export function flattenFields(groups = []) {
    return groups.reduce((carry, group) => carry.concat(Array.isArray(group?.fields) ? group.fields : []), []);
}

/**
 * The structure as the server writes it. `order` is rewritten from the
 * builder's own ordering so a drag or a delete renumbers the whole form, and
 * the uuid is kept so a second save updates rather than duplicates.
 */
export function serializeFieldGroups(groups = []) {
    return groups.map((group, groupIndex) => ({
        uuid: group.uuid,
        name: group.name,
        description: group.description ?? null,
        order: groupIndex + 1,
        meta: metaOf(group.meta),
        fields: (Array.isArray(group.fields) ? group.fields : []).map((field, fieldIndex) => ({
            uuid: field.uuid,
            name: field.name || null,
            label: field.label,
            description: field.description ?? null,
            help_text: field.help_text ?? null,
            type: field.type,
            component: componentForFieldType(field.type),
            required: Boolean(field.required),
            editable: field.editable === undefined ? true : Boolean(field.editable),
            options: Array.isArray(field.options) ? field.options.filter((option) => typeof option === 'string' && option.trim() !== '') : [],
            order: fieldIndex + 1,
            meta: metaOf(field.meta),
        })),
    }));
}

/** A blank group, ready for the builder to name. */
export function createFieldGroup(attributes = {}) {
    return normalizeGroup({ uuid: generateUuid(), name: '', meta: { grid_size: 1 }, fields: [], ...attributes });
}

/** A blank field of the given type. */
export function createField(type = 'pass-fail', attributes = {}) {
    const meta = type === 'pass-fail' ? { severity: 'medium', require_photo_on_fail: false, require_comment_on_fail: false, unsafe_on_fail: false } : {};

    return normalizeField({ uuid: generateUuid(), label: '', type, meta, ...attributes });
}
