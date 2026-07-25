<?php

namespace Illuminate\Validation {
    if (!class_exists(Rule::class)) {
        class Rule
        {
            public function __construct(private string $rule)
            {
            }

            public static function requiredIf($condition): string
            {
                return (is_callable($condition) ? $condition() : $condition) ? 'required' : 'nullable';
            }

            public static function in(array $values): self
            {
                return new self('in:' . implode(',', $values));
            }

            public static function exists($table, $column = null): self
            {
                return new self('exists:' . $table . ($column ? ',' . $column : ''));
            }

            public static function unique($table, $column = null): self
            {
                return new self('unique:' . $table . ($column ? ',' . $column : ''));
            }

            public static function when($condition, array $rules): array
            {
                return (is_callable($condition) ? $condition() : $condition) ? $rules : [];
            }

            public function where($callback): self
            {
                return $this;
            }

            public function whereNull($column): self
            {
                return $this;
            }

            public function __toString(): string
            {
                return $this->rule;
            }
        }
    }
}

namespace {
    use Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateTrackingStatusRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\CreateDriverRequest as InternalCreateDriverRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateDeviceRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateWorkOrderRequest;
    use Fleetbase\FleetOps\Rules\ResolvablePoint;
    use Fleetbase\FleetOps\Rules\ResolvableVehicle;

    function requestRules(string $class, string $method = 'POST'): array
    {
        return $class::create('/fleetops-test', $method)->rules();
    }

    function ruleStrings(array $rules): array
    {
        $strings = [];

        array_walk_recursive($rules, function ($rule) use (&$strings) {
            if ($rule instanceof Closure) {
                return;
            }

            $strings[] = (string) $rule;
        });

        return $strings;
    }

    test('device requests require names on create and protect paired location fields', function () {
        $createRules = requestRules(CreateDeviceRequest::class);
        $updateRules = requestRules(UpdateDeviceRequest::class, 'PATCH');

        expect(ruleStrings($createRules['name']))->toContain('required', 'string')
            ->and(ruleStrings($updateRules['name']))->not->toContain('required')
            ->and($createRules['last_position'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($createRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
            ->and($createRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
            ->and($createRules['attachable'])->toBe(['nullable', 'required_with:attachable_type', 'string'])
            ->and($createRules['meta'])->toBe(['nullable', 'array'])
            ->and($createRules['options'])->toBe(['nullable', 'array']);
    });

    test('work order requests require subjects on create and preserve cost metadata rules', function () {
        $createRules = requestRules(CreateWorkOrderRequest::class);
        $updateRules = requestRules(UpdateWorkOrderRequest::class, 'PATCH');

        expect(ruleStrings($createRules['subject']))->toContain('required', 'string')
            ->and(ruleStrings($updateRules['subject']))->not->toContain('required')
            ->and($createRules['target'])->toBe(['nullable', 'required_with:target_type', 'string'])
            ->and($createRules['assignee'])->toBe(['nullable', 'required_with:assignee_type', 'string'])
            ->and($createRules['currency'])->toBe(['nullable', 'string', 'size:3'])
            ->and($createRules['checklist'])->toBe(['nullable', 'array'])
            ->and($createRules['cost_breakdown'])->toBe(['nullable', 'array'])
            ->and($createRules['meta'])->toBe(['nullable', 'array']);
    });

    test('fuel transaction request requires provider identifiers on create', function () {
        $createRules = requestRules(CreateFuelTransactionRequest::class);
        $patchRules  = requestRules(CreateFuelTransactionRequest::class, 'PATCH');

        expect(ruleStrings($createRules['provider']))->toContain('required', 'string')
            ->and(ruleStrings($createRules['provider_transaction_id']))->toContain('required', 'string')
            ->and(ruleStrings($patchRules['provider']))->not->toContain('required')
            ->and(ruleStrings($patchRules['provider_transaction_id']))->not->toContain('required')
            ->and($createRules['station_latitude'])->toBe(['nullable', 'numeric'])
            ->and($createRules['station_longitude'])->toBe(['nullable', 'numeric'])
            ->and($createRules['normalized_payload'])->toBe(['nullable', 'array'])
            ->and($createRules['raw_payload'])->toBe(['nullable', 'array'])
            ->and($createRules['meta'])->toBe(['nullable', 'array']);
    });

    test('vehicle and fuel report requests expose core validation contracts', function () {
        $vehicleRules          = requestRules(CreateVehicleRequest::class);
        $fuelReportRules       = requestRules(CreateFuelReportRequest::class);
        $fuelReportUpdateRules = requestRules(UpdateFuelReportRequest::class, 'PATCH');

        expect($vehicleRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($vehicleRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
            ->and($vehicleRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
            ->and(ruleStrings($vehicleRules['status']))->toContain('nullable')
            ->and(implode('|', ruleStrings($vehicleRules['status'])))->toContain('operational')
            ->and($fuelReportRules['driver'])->toBe(['required'])
            ->and($fuelReportRules['odometer'])->toBe(['required'])
            ->and($fuelReportRules['volume'])->toBe(['required'])
            ->and($fuelReportUpdateRules['driver'])->toBe(['required']);
    });

    test('tracking status request switches between tracking number order and coordinate contracts', function () {
        $trackingRules = CreateTrackingStatusRequest::create('/fleetops-test', 'POST', [
            'tracking_number' => 'tracking_number_abc1234',
            'location'        => ['latitude' => 1.3521, 'longitude' => 103.8198],
        ])->rules();

        expect(ruleStrings($trackingRules['tracking_number']))->toContain('required', 'exists:tracking_numbers,public_id')
            ->and($trackingRules['tracking_number'][2])->toBeInstanceOf(Closure::class)
            ->and($trackingRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and(ruleStrings($trackingRules['code']))->toContain('required', 'string', 'min:3')
            ->and(ruleStrings($trackingRules['status']))->toContain('required', 'string', 'min:3')
            ->and(ruleStrings($trackingRules['details']))->toContain('required', 'string', 'min:3');

        $coordinateRules = CreateTrackingStatusRequest::create('/fleetops-test', 'POST', [
            'tracking_number' => 'tracking_number_abc1234',
            'latitude'        => 1.3521,
            'longitude'       => 103.8198,
        ])->rules();

        expect($coordinateRules['latitude'])->toBe(['required'])
            ->and($coordinateRules['longitude'])->toBe(['required']);

        $orderRules = CreateTrackingStatusRequest::create('/fleetops-test', 'POST', [
            'order'  => 'order_abc1234',
            'status' => 'delivered',
        ])->rules();

        expect($orderRules['tracking_number'])->toBe('nullable')
            ->and(ruleStrings($orderRules['order']))->toContain('required', 'exists:orders,public_id')
            ->and($orderRules['order'][2])->toBeInstanceOf(Closure::class);

        $duplicateRequest = CreateTrackingStatusRequest::create('/fleetops-test', 'POST', [
            'tracking_number' => 'tracking_number_abc1234',
            'status'          => 'delivered',
            'duplicate'       => true,
        ]);
        $duplicateRule = $duplicateRequest->rules()['tracking_number'][2];
        $failed        = false;

        $duplicateRule('tracking_number', 'tracking_number_abc1234', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    test('internal create driver request exposes user identity vehicle and message contracts', function () {
        $createRules = InternalCreateDriverRequest::create('/fleetops-test', 'POST')->rules();
        $userRules   = InternalCreateDriverRequest::create('/fleetops-test', 'POST', [
            'driver' => ['user_uuid' => 'user_uuid'],
        ])->rules();
        $patchRules  = InternalCreateDriverRequest::create('/fleetops-test', 'PATCH')->rules();
        $request     = new InternalCreateDriverRequest();

        expect(ruleStrings($createRules['name']))->toContain('required', 'nullable', 'string', 'max:255')
            ->and(ruleStrings($createRules['email']))->toContain('required')
            ->and(ruleStrings($createRules['phone']))->toContain('required')
            ->and(ruleStrings($userRules['name']))->not->toContain('required')
            ->and(ruleStrings($patchRules['name']))->not->toContain('required')
            ->and($createRules['vehicle'][1])->toBeInstanceOf(ResolvableVehicle::class)
            ->and($createRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($createRules['latitude'])->toBe(['nullable', 'required_with:longitude', 'numeric'])
            ->and($createRules['longitude'])->toBe(['nullable', 'required_with:latitude', 'numeric'])
            ->and($createRules['password'])->toBe('nullable|string|min:8')
            ->and($request->attributes())->toMatchArray([
                'name'       => 'driver name',
                'email'      => 'email address',
                'photo_uuid' => 'photo',
            ])
            ->and($request->messages())->toMatchArray([
                'name.required'  => 'Driver name is required.',
                'email.required' => 'Email address is required.',
                'password.min'   => 'Password must be at least 8 characters.',
            ]);
    });
}
