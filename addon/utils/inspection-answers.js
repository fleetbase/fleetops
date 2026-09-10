/**
 * Reading an inspection's answers.
 *
 * The sheet, its section headers and its running total all need the same
 * questions answered — did this row pass, is a required row still blank, is
 * anything on this form unsafe to operate — and they must agree, so they ask
 * here rather than each working it out from the raw value.
 *
 * The rules mirror the driver app's `src/v3/data/useInspections.ts`, so a form
 * filled in from a phone and the same form filled in from the console are
 * counted the same way.
 */

import { flattenFields } from './inspection-form-structure';
import { valueTypeForFieldType } from './inspection-field-types';

/** A pass-fail answer, in one shape whatever was stored. */
export function passFailAnswer(value) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return value;
    }

    // The first cut stored a bare boolean.
    if (typeof value === 'boolean') {
        return { passed: value, not_applicable: false };
    }

    return null;
}

/**
 * What a pass-fail row currently says: `pass`, `fail`, `na`, or null when the
 * field is not a pass-fail field at all.
 */
export function answerState(field, value) {
    if (field?.type !== 'pass-fail') {
        return null;
    }

    const answer = passFailAnswer(value);
    if (!answer) {
        return null;
    }

    if (answer.not_applicable === true) {
        return 'na';
    }

    return answer.passed === false ? 'fail' : 'pass';
}

/** Whether a failed row was marked unsafe to operate. */
export function isUnsafeAnswer(field, value) {
    return answerState(field, value) === 'fail' && passFailAnswer(value)?.unsafe === true;
}

/**
 * Whether a required field is still waiting for an answer.
 *
 * A pass-fail row is never blank — it opens on Pass, which is what the driver
 * app does too — and a toggle is never blank, because off is an answer.
 */
export function isBlank(field, value) {
    if (field?.type === 'pass-fail' || field?.type === 'boolean') {
        return false;
    }

    if (value === null || value === undefined || value === '') {
        return true;
    }

    return Array.isArray(value) && value.length === 0;
}

/** Whether a failed row is still missing the comment or photo its field demands. */
export function defectIncomplete(field, value) {
    if (answerState(field, value) !== 'fail') {
        return false;
    }

    const meta = field?.meta && typeof field.meta === 'object' ? field.meta : {};
    const answer = passFailAnswer(value) ?? {};

    if (meta.require_comment_on_fail === true && !String(answer.comments ?? '').trim()) {
        return true;
    }

    return meta.require_photo_on_fail === true && !(Array.isArray(answer.photos) && answer.photos.length > 0);
}

/**
 * The totals a section header and the sheet's foot both read.
 *
 * `outstanding` is what still stops the sheet being finished: a required field
 * left blank, or a failure that owes a comment or a photo.
 */
export function summarize(fields = [], values = {}) {
    const summary = {
        total: fields.length,
        checks: 0,
        passed: 0,
        failed: 0,
        notApplicable: 0,
        missingRequired: 0,
        incompleteDefects: 0,
        unsafe: false,
        unsafeField: null,
        firstOutstanding: null,
    };

    for (const field of fields) {
        const value = values?.[field.uuid];
        const state = answerState(field, value);

        if (state) {
            summary.checks += 1;

            if (state === 'pass') {
                summary.passed += 1;
            } else if (state === 'fail') {
                summary.failed += 1;
            } else {
                summary.notApplicable += 1;
            }
        }

        if (field.required && isBlank(field, value)) {
            summary.missingRequired += 1;
        }

        if (defectIncomplete(field, value)) {
            summary.incompleteDefects += 1;
        }

        // The banners name the field rather than counting it, so an inspector
        // is told what to go and fix, not how many things are wrong.
        if (!summary.firstOutstanding && fieldMarker(field, value) === 'outstanding') {
            summary.firstOutstanding = field;
        }

        if (isUnsafeAnswer(field, value)) {
            summary.unsafe = true;

            if (!summary.unsafeField) {
                summary.unsafeField = field;
            }
        }
    }

    summary.outstanding = summary.missingRequired + summary.incompleteDefects;

    return summary;
}

/**
 * The answer every field starts with.
 *
 * A pass-fail row opens on Pass, so a sheet saved untouched still files a
 * complete set — which is what the driver app does, and what the first cut of
 * the console did. Everything else starts empty.
 */
export function seedAnswers(groups = [], stored = {}) {
    return flattenFields(groups).reduce((carry, field) => {
        if (stored?.[field.uuid] !== undefined) {
            carry[field.uuid] = stored[field.uuid];
            return carry;
        }

        carry[field.uuid] = field.type === 'pass-fail' ? { passed: true, not_applicable: false, severity: null, comments: '', photos: [], unsafe: false } : null;

        return carry;
    }, {});
}

/**
 * A file value is a reference on the way out, whichever way it came in: the
 * `file:<uuid>` a fresh upload leaves, or the `{ id, url, … }` the submission
 * resource resolved a stored reference to.
 */
function serializeFile(value) {
    if (value && typeof value === 'object') {
        return value.id ?? null;
    }

    return typeof value === 'string' && value !== '' ? value : null;
}

function serializeValue(field, value) {
    if (field.type === 'pass-fail') {
        const answer = passFailAnswer(value) ?? { passed: true, not_applicable: false };

        return {
            ...answer,
            photos: (Array.isArray(answer.photos) ? answer.photos : []).map(serializeFile).filter(Boolean),
        };
    }

    if (field.type === 'file-upload' || field.type === 'signature') {
        return serializeFile(value);
    }

    return value;
}

/**
 * The answers as the server takes them — the same `custom_field_values` body
 * the driver API accepts, so the console, a public link and the app all file
 * the same rows and the item results are derived from the same place.
 */
export function answerRows(fields = [], values = {}) {
    return fields.map((field) => ({
        custom_field: field.uuid,
        value_type: valueTypeForFieldType(field.type),
        value: serializeValue(field, values?.[field.uuid]),
    }));
}
