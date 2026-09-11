<?php

use Fleetbase\FleetOps\Support\InspectionSubmitter;

/*
 * The public link and `POST /v1/inspections` both hand the submitter what
 * validate() returns, and validate() returns only keys that have a rule: once
 * an array has nested rules, its other keys are dropped. `value` had no rule of
 * its own, so every answer reached the submitter empty and a failed check
 * carrying its photo was refused for having none.
 *
 * The rules are checked here rather than run through a validator, which this
 * package does not install.
 */
test('the submitter rules give every key of an answer a rule of its own', function () {
    $rules = InspectionSubmitter::rules();

    expect($rules)->toHaveKeys([
        'custom_field_values',
        'custom_field_values.*.custom_field',
        'custom_field_values.*.custom_field_uuid',
        'custom_field_values.*.value',
        'custom_field_values.*.value_type',
    ]);

    // An empty answer is still an answer (it clears the field), so the value
    // is allowed to be null rather than required.
    expect($rules['custom_field_values.*.value'])->toBe('nullable')
        ->and($rules['custom_field_values.*.custom_field_uuid'])->toContain('nullable');

    // Every key of a flat item result has a rule too, so none of them is dropped.
    foreach (['item_key', 'label', 'category', 'status', 'severity', 'passed', 'comments', 'photos'] as $key) {
        expect($rules)->toHaveKey('item_results.*.' . $key);
    }
});
