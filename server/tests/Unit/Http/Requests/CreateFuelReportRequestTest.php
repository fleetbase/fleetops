<?php

namespace Illuminate\Foundation\Http {
    if (!class_exists(FormRequest::class)) {
        class FormRequest extends \Illuminate\Http\Request
        {
        }
    }
}

namespace {
    use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;

    class FleetOpsCreateFuelReportSessionStore
    {
        public function __construct(private array $values)
        {
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->values);
        }
    }

    class FleetOpsCreateFuelReportRequestState
    {
        public static ?FleetOpsCreateFuelReportSessionStore $session = null;
    }

    if (!function_exists('Fleetbase\FleetOps\Http\Requests\request')) {
        eval('namespace Fleetbase\FleetOps\Http\Requests; function request($key = null, $default = null) { return new class { public function session(): \FleetOpsCreateFuelReportSessionStore { return \FleetOpsCreateFuelReportRequestState::$session ?? new \FleetOpsCreateFuelReportSessionStore([]); } }; }');
    }

    function fleetopsCreateFuelReportRequestWithSession(array $sessionData): CreateFuelReportRequest
    {
        FleetOpsCreateFuelReportRequestState::$session = new FleetOpsCreateFuelReportSessionStore($sessionData);

        return CreateFuelReportRequest::create('/fleet-ops/fuel-reports', 'POST');
    }

    test('fuel report create only fields are required on POST and optional on update', function () {
        FleetOpsCreateFuelReportRequestState::$session = new FleetOpsCreateFuelReportSessionStore(['api_credential' => 'api-credential-uuid']);

        // UpdateFuelReportRequest extends this class, so a flat `required` made every
        // partial update fail — PUT with just {"status": "approved"} answered "The driver
        // field is required." `driver` is the plainest case: the update action's
        // $request->only() list does not include it, so the field was demanded and then
        // discarded.
        $create = CreateFuelReportRequest::create('/fleet-ops/fuel-reports', 'POST')->rules();
        $update = UpdateFuelReportRequest::create('/fleet-ops/fuel-reports/report_1', 'PUT')->rules();

        foreach (['driver', 'odometer', 'volume'] as $field) {
            expect($create[$field])->toBe(['required'])
                ->and($update[$field])->toBe(['sometimes']);
        }

        expect($create['metric_unit'])->toBe(['nullable'])
            ->and($create['amount'])->toBe(['nullable']);
    });

    test('create fuel report authorization accepts api credentials or sanctum sessions', function () {
        expect(fleetopsCreateFuelReportRequestWithSession([])->authorize())->toBeFalse()
            ->and(fleetopsCreateFuelReportRequestWithSession(['api_credential' => 'api-credential-uuid'])->authorize())->toBeTrue()
            ->and(fleetopsCreateFuelReportRequestWithSession(['is_sanctum_token' => true])->authorize())->toBeTrue();
    });
}
