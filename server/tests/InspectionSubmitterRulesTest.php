<?php

use Fleetbase\FleetOps\Support\InspectionSubmitter;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

/*
 * The public link and `POST /v1/inspections` both hand the submitter what
 * validate() returns, and validate() returns only keys that have a rule: with
 * nested rules present, an array's other keys are dropped. `value` had no rule
 * of its own, so every answer reached the submitter empty and a failed check
 * carrying its photo was refused for having none.
 */
test('the submitter rules keep every key of an answer through validation', function () {
    $factory = new Factory(new Translator(new ArrayLoader(), 'en'));

    $validated = $factory->make([
        'custom_field_values' => [
            ['custom_field' => 'mirrors', 'value_type' => 'object', 'value' => ['passed' => false, 'comments' => 'Cracked', 'photos' => ['file:file_example']]],
            ['custom_field_uuid' => 'odometer-field', 'value_type' => 'number', 'value' => 209],
            ['custom_field' => 'notes', 'value_type' => 'text', 'value' => null],
        ],
    ], InspectionSubmitter::rules())->validate();

    [$failed, $number, $empty] = $validated['custom_field_values'];

    expect($failed['value'])->toBe(['passed' => false, 'comments' => 'Cracked', 'photos' => ['file:file_example']])
        ->and($number['custom_field_uuid'])->toBe('odometer-field')
        ->and($number['value'])->toBe(209)
        // A deliberately empty answer is still an answer: it clears the field.
        ->and($empty)->toHaveKey('value')
        ->and($empty['value'])->toBeNull();
});
