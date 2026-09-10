/**
 * The field types an inspection form may be built from.
 *
 * This list mirrors `Fleetbase\FleetOps\Models\InspectionForm::FIELD_TYPES`
 * exactly — the server refuses anything else and falls back to `input`, so the
 * builder must not offer a type the writer will silently rewrite.
 */
export const INSPECTION_FIELD_TYPES = ['pass-fail', 'input', 'textarea', 'number', 'select', 'radio-button', 'boolean', 'date-picker', 'date-time-input', 'file-upload', 'signature'];

/** The severities a failed pass-fail answer can carry. */
export const INSPECTION_SEVERITIES = ['low', 'medium', 'high', 'critical'];

/** The types whose answer is chosen from a list the author writes. */
export const OPTION_FIELD_TYPES = ['select', 'radio-button'];

/**
 * The types FleetOps renders itself. `pass-fail`, `signature` and the
 * inspection flavour of `file-upload` are inspection-only; `textarea`,
 * `number` and `boolean` are ordinary but absent from the platform's
 * custom-field type map, so there is nothing to delegate them to.
 */
export const FLEETOPS_OWNED_FIELD_TYPES = ['pass-fail', 'signature', 'file-upload', 'textarea', 'number', 'boolean'];

/** The types the platform's own `custom-field/input` already renders. */
export const DELEGATED_FIELD_TYPES = ['input', 'select', 'radio-button', 'date-picker', 'date-time-input'];

/**
 * The console component that renders a field type. Mirrors
 * `InspectionFormSync::componentFor()` so a field built here and a field
 * converted from the first cut's checklist name the same component.
 */
export function componentForFieldType(type) {
    return type === 'radio-button' ? 'radio-button-select' : type;
}

/**
 * How the server stores an answer of this type — the `value_type` a submitted
 * `custom_field_values` row carries. Mirrors
 * `InspectionSubmitter::normalizeValue()`.
 */
export function valueTypeForFieldType(type) {
    switch (type) {
        case 'pass-fail':
            return 'object';
        case 'file-upload':
        case 'signature':
            return 'file';
        case 'number':
            return 'number';
        case 'boolean':
            return 'boolean';
        case 'date-picker':
        case 'date-time-input':
            return 'date';
        default:
            return 'text';
    }
}

/** Whether a field of this type needs the author to write its options. */
export function isOptionFieldType(type) {
    return OPTION_FIELD_TYPES.includes(type);
}

/** Whether FleetOps renders this type itself rather than delegating it. */
export function isFleetOpsOwnedFieldType(type) {
    return FLEETOPS_OWNED_FIELD_TYPES.includes(type);
}

export default function inspectionFieldTypes() {
    return INSPECTION_FIELD_TYPES;
}
