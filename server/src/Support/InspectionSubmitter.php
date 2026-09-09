<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Rules\Base64OrUrl;

/**
 * Records an inspection against a published form.
 *
 * Two doors lead here — the tokenised public link and the driver API — and
 * they must agree on what a submission is: one row, one item result per item,
 * the pass/fail counts, and whatever follow-up the form's settings ask for.
 * The doors differ only in who they say submitted it, which is what the
 * `$attributes` argument carries.
 */
class InspectionSubmitter
{
    /**
     * The body of a submit, as both doors accept it.
     */
    public static function rules(): array
    {
        return [
            'odometer'                => 'nullable|integer|min:0',
            'engine_hours'            => 'nullable|integer|min:0',
            'item_results'            => 'required|array|min:1',
            'item_results.*.item_key' => 'nullable|string|max:191',
            'item_results.*.label'    => 'required|string|max:255',
            'item_results.*.category' => 'nullable|string|max:191',
            'item_results.*.status'   => 'nullable|string|max:50',
            'item_results.*.severity' => 'nullable|string|max:50',
            'item_results.*.passed'   => 'required|boolean',
            'item_results.*.comments' => 'nullable|string|max:2000',
            'item_results.*.photos'   => 'nullable|array',
            'item_results.*.photos.*' => ['string', new Base64OrUrl()],
            'location'                => 'nullable|array',
            'signature'               => 'nullable|array',
            'attachments'             => 'nullable|array',
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

        foreach ($validated['item_results'] as $item) {
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

        $submission->syncResultCounts();

        if (data_get($form->settings, 'create_issue_on_failure') && $submission->has_failures) {
            $submission->createIssueFromFailures();
        }

        if (data_get($form->settings, 'create_work_order_on_failure') && $submission->has_failures) {
            $submission->createWorkOrderFromFailures();
        }

        return $submission;
    }
}
