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

    test('create fuel report authorization accepts api credentials or sanctum sessions', function () {
        expect(fleetopsCreateFuelReportRequestWithSession([])->authorize())->toBeFalse()
            ->and(fleetopsCreateFuelReportRequestWithSession(['api_credential' => 'api-credential-uuid'])->authorize())->toBeTrue()
            ->and(fleetopsCreateFuelReportRequestWithSession(['is_sanctum_token' => true])->authorize())->toBeTrue();
    });
}
