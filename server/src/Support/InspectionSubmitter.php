<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Rules\Base64OrUrl;
use Fleetbase\Models\CustomField;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records an inspection against a published form.
 *
 * Two doors lead here — the tokenised public link and the driver API — and
 * they must agree on what a submission is: one row, the answers, the pass/fail
 * counts, and whatever follow-up the form's settings ask for. The doors differ
 * only in who they say submitted it, which is what the `$attributes` argument
 * carries.
 *
 * A form built from fields is answered with `custom_field_values`: one value
 * per field, stored through the platform's custom-field values, with every
 * `pass-fail` answer mirrored into an item result. The first cut's
 * `item_results` body is still accepted for the public link and older app
 * builds; when both arrive the field values win and the duplicated results
 * are ignored.
 */
class InspectionSubmitter
{
    /**
     * The body of a submit, as both doors accept it.
     */
    public static function rules(): array
    {
        return [
            'odometer'                            => 'nullable|integer|min:0',
            'engine_hours'                        => 'nullable|integer|min:0',
            'custom_field_values'                 => 'required_without:item_results|array',
            'custom_field_values.*.custom_field'  => 'required_without:custom_field_values.*.custom_field_uuid|string|max:191',
            'custom_field_values.*.value_type'    => 'nullable|string|max:50',
            'item_results'                        => 'required_without:custom_field_values|array',
            'item_results.*.item_key'             => 'nullable|string|max:191',
            'item_results.*.label'                => 'required|string|max:255',
            'item_results.*.category'             => 'nullable|string|max:191',
            'item_results.*.status'               => 'nullable|string|max:50',
            'item_results.*.severity'             => 'nullable|string|max:50',
            'item_results.*.passed'               => 'required|boolean',
            'item_results.*.comments'             => 'nullable|string|max:2000',
            'item_results.*.photos'               => 'nullable|array',
            'item_results.*.photos.*'             => ['string', new Base64OrUrl()],
            'location'                            => 'nullable|array',
            'signature'                           => 'nullable|array',
            'attachments'                         => 'nullable|array',
        ];
    }

    /**
     * @param array $validated  the request body, already validated against rules()
     * @param array $attributes columns the door owns: vehicle_uuid, driver_uuid,
     *                          submitted_by_uuid, source, started_at, meta
     */
    public static function submit(InspectionForm $form, array $validated, array $attributes = []): InspectionSubmission
    {
        $submission = InspectionSubmission::create(array_merge([
            'company_uuid'         => $form->company_uuid,
            'inspection_form_uuid' => $form->uuid,
            'type'                 => $form->type ?? 'dvir',
            'status'               => 'submitted',
            'odometer'             => data_get($validated, 'odometer'),
            'engine_hours'         => data_get($validated, 'engine_hours'),
            'started_at'           => now(),
            'submitted_at'         => now(),
            'location'             => data_get($validated, 'location'),
            'signature'            => data_get($validated, 'signature'),
            'attachments'          => data_get($validated, 'attachments'),
        ], $attributes));

        $values = data_get($validated, 'custom_field_values');
        if (is_array($values) && !empty($values)) {
            static::applyCustomFieldValues($submission, $values, $submission->submitted_by_uuid);
        } else {
            foreach ((array) data_get($validated, 'item_results', []) as $item) {
                InspectionItemResult::create([
                    'company_uuid'               => $form->company_uuid,
                    'inspection_submission_uuid' => $submission->uuid,
                    'item_key'                   => data_get($item, 'item_key'),
                    'label'                      => data_get($item, 'label'),
                    'category'                   => data_get($item, 'category'),
                    'status'                     => data_get($item, 'status', data_get($item, 'passed') ? 'passed' : 'failed'),
                    'severity'                   => data_get($item, 'severity'),
                    'passed'                     => (bool) data_get($item, 'passed'),
                    'comments'                   => data_get($item, 'comments'),
                    'photos'                     => data_get($item, 'photos'),
                ]);
            }
        }

        $submission->syncResultCounts();

        if (data_get($form->settings, 'create_issue_on_failure') && $submission->has_failures) {
            $submission->createIssueFromFailures();
        }

        if (data_get($form->settings, 'create_work_order_on_failure') && $submission->has_failures) {
            $submission->createWorkOrderFromFailures();
        }

        return $submission;
    }

    /**
     * Stores a set of answers against the submission's form.
     *
     * Each row names a field of the form by uuid (or name), carries a value
     * and, optionally, a value type. A field that does not belong to the form,
     * or a failed pass-fail answer missing the comment or photo the field
     * insists on, refuses the whole set with a 422 so nothing half-filled is
     * written. Base64 photos and signatures become platform files on the way
     * in; when the answers are in, the item results and the counts follow.
     *
     * @param array       $rows         [{custom_field|custom_field_uuid, value, value_type}]
     * @param string|null $uploaderUuid the user a stored photo or signature is credited to
     *
     * @throws ValidationException
     */
    public static function applyCustomFieldValues(InspectionSubmission $submission, array $rows, ?string $uploaderUuid = null): array
    {
        $fields  = CustomField::query()->where('subject_uuid', $submission->inspection_form_uuid)->where('for', InspectionForm::FIELD_FOR)->get();
        $payload = [];
        $errors  = [];

        foreach (array_values($rows) as $index => $row) {
            $key   = Arr::get($row, 'custom_field', Arr::get($row, 'custom_field_uuid'));
            $field = static::findField($fields, $key);
            if (!$field) {
                $errors["custom_field_values.{$index}.custom_field"] = ['The field "' . (is_scalar($key) ? $key : '?') . '" does not belong to this inspection form.'];
                continue;
            }

            [$value, $valueType, $fieldErrors] = static::normalizeValue($field, Arr::get($row, 'value'), Arr::get($row, 'value_type'), $submission, $uploaderUuid);
            foreach ($fieldErrors as $message) {
                $errors["custom_field_values.{$index}.value"][] = $message;
            }

            $payload[] = [
                'custom_field_uuid' => $field->uuid,
                'value'             => $value,
                'value_type'        => $valueType,
            ];
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $summary = $submission->syncCustomFieldValues($payload);
        $submission->syncItemResultsFromCustomFieldValues();
        $submission->syncResultCounts();

        return $summary;
    }

    /** Finds a field of the form by uuid or, failing that, by its name. */
    protected static function findField(Collection $fields, mixed $key): ?CustomField
    {
        if (!is_string($key) || $key === '') {
            return null;
        }

        return $fields->first(fn (CustomField $field) => $field->uuid === $key)
            ?? $fields->first(fn (CustomField $field) => $field->name === $key);
    }

    /**
     * The value as it is stored, its type, and anything wrong with it.
     *
     * @return array{0: mixed, 1: string, 2: string[]}
     */
    protected static function normalizeValue(CustomField $field, mixed $value, mixed $valueType, InspectionSubmission $submission, ?string $uploaderUuid): array
    {
        $errors = [];

        switch ($field->type) {
            case 'pass-fail':
                $answer = static::passFailAnswer($value);
                $failed = !$answer['not_applicable'] && !filter_var($answer['passed'], FILTER_VALIDATE_BOOLEAN);
                $meta   = is_array($field->meta) ? $field->meta : [];
                $photos = array_values(array_filter($answer['photos'], 'is_string'));

                // What the field insists on is checked before anything is
                // stored, so a refused answer leaves no orphan photo behind.
                if ($failed) {
                    if (data_get($meta, 'require_comment_on_fail') && trim((string) $answer['comments']) === '') {
                        $errors[] = 'A comment is required when "' . $field->label . '" fails.';
                    }
                    if (data_get($meta, 'require_photo_on_fail') && empty($photos)) {
                        $errors[] = 'A photo is required when "' . $field->label . '" fails.';
                    }
                }

                if (!empty($errors)) {
                    return [$answer, 'object', $errors];
                }

                $answer['photos'] = array_values(array_map(
                    fn ($photo) => InspectionFileStore::normalize($photo, $submission, InspectionFileStore::TYPE_PHOTO, $uploaderUuid),
                    $photos
                ));

                if ($failed) {
                    $answer['severity'] = $answer['severity'] ?? data_get($meta, 'severity');
                    $answer['unsafe']   = filter_var($answer['unsafe'] ?? data_get($meta, 'unsafe_on_fail', false), FILTER_VALIDATE_BOOLEAN);
                } else {
                    $answer['severity'] = null;
                    $answer['unsafe']   = false;
                }

                return [$answer, 'object', $errors];

            case 'file-upload':
            case 'signature':
                $type = $field->type === 'signature' ? InspectionFileStore::TYPE_SIGNATURE : InspectionFileStore::TYPE_PHOTO;

                return [InspectionFileStore::normalize($value, $submission, $type, $uploaderUuid), 'file', $errors];

            case 'number':
                return [$value === null || $value === '' ? null : (is_numeric($value) ? $value + 0 : $value), 'number', $errors];

            case 'boolean':
                return [filter_var($value, FILTER_VALIDATE_BOOLEAN), 'boolean', $errors];

            default:
                if (is_array($value)) {
                    return [$value, 'array', $errors];
                }

                return [$value, is_string($valueType) && $valueType !== '' ? $valueType : 'text', $errors];
        }
    }

    /**
     * A pass-fail answer in one shape, whatever the client sent: an object,
     * its JSON, a bare boolean, or the words "pass" / "fail".
     *
     * "Not applicable" is an answer of its own — the app sends it as
     * `passed: true, not_applicable: true`, and it must not read as a pass
     * once it is a result row, or a tail lift a rigid does not have would
     * count towards the vehicle's clean record.
     */
    public static function passFailAnswer(mixed $value): array
    {
        if (is_string($value) && Str::startsWith(trim($value), '{')) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : $value;
        }

        if (!is_array($value)) {
            $passed = is_string($value) ? !in_array(Str::lower(trim($value)), ['fail', 'failed', 'false', '0', 'no'], true) : (bool) $value;
            $value  = ['passed' => $passed];
        }

        $notApplicable = filter_var($value['not_applicable'] ?? $value['na'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($value['passed'] ?? $value['pass'] ?? true) === null;

        return [
            'passed'         => $notApplicable ? true : filter_var($value['passed'] ?? $value['pass'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'not_applicable' => $notApplicable,
            'severity'       => isset($value['severity']) ? (Str::slug((string) $value['severity']) ?: null) : null,
            'comments'       => isset($value['comments']) ? (string) $value['comments'] : null,
            'photos'         => is_array($value['photos'] ?? null) ? $value['photos'] : [],
            'unsafe'         => $value['unsafe'] ?? null,
        ];
    }
}
