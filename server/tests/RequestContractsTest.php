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
    use Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\CreatePlaceRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateSensorRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateServiceAreaRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateServiceRateRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateTrackingStatusRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\CreateDriverRequest as InternalCreateDriverRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderRequest as InternalCreateOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateDeviceRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateWorkOrderRequest;
    use Fleetbase\FleetOps\Rules\ComputableAlgo;
    use Fleetbase\FleetOps\Rules\CustomerIdOrDetails;
    use Fleetbase\FleetOps\Rules\ResolvablePoint;
    use Fleetbase\FleetOps\Rules\ResolvableVehicle;
    use Fleetbase\Rules\ExistsInAny;

    function requestRules(string $class, string $method = 'POST'): array
    {
        return $class::create('/fleetops-test', $method)->rules();
    }

    function bindFleetOpsRequestSession(array $data = []): void
    {
        $session = app('session.store');
        $session->flush();

        foreach ($data as $key => $value) {
            $session->put($key, $value);
        }

        $request = Illuminate\Http\Request::create('/fleetops-test', 'POST');
        $request->setLaravelSession($session);

        app()->instance('request', $request);
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

    class FleetOpsPublicCreateOrderRequestProbe extends CreateOrderRequest
    {
        public function isArray(string $key): bool
        {
            return is_array($this->input($key));
        }

        public function isString(string $key): bool
        {
            return is_string($this->input($key));
        }
    }

    class FleetOpsInternalCreateOrderRequestProbe extends InternalCreateOrderRequest
    {
        public function isArray(string $key): bool
        {
            return is_array($this->input($key));
        }
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

    test('public create order request validates payload alternatives and pod methods', function () {
        app('config')->set('fleetops.pod_methods', ['scan', 'signature']);

        $baseRules = FleetOpsPublicCreateOrderRequestProbe::create('/fleetops-test', 'POST')->rules();

        expect($baseRules['pickup'])->toBe('required')
            ->and($baseRules['dropoff'])->toBe('required')
            ->and($baseRules['waypoints'])->toBe('required|array|min:2')
            ->and($baseRules['facilitator'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($baseRules['customer'][1])->toBeInstanceOf(CustomerIdOrDetails::class);

        $payloadRules = FleetOpsPublicCreateOrderRequestProbe::create('/fleetops-test', 'POST', [
            'payload' => [
                'entities' => [],
            ],
        ])->rules();

        expect($payloadRules['payload'])->toBe('required')
            ->and($payloadRules['payload.entities'])->toBe('array')
            ->and($payloadRules['payload.pickup'])->toBe('required')
            ->and($payloadRules['payload.dropoff'])->toBe('required')
            ->and($payloadRules['payload.waypoints'])->toBe('required|array|min:2')
            ->and($payloadRules['payload.return'])->toBe('nullable');

        $payloadIdRules = FleetOpsPublicCreateOrderRequestProbe::create('/fleetops-test', 'POST', [
            'payload' => 'payload_abc1234',
        ])->rules();

        expect($payloadIdRules['payload'])->toBe('required|exists:payloads,public_id')
            ->and($payloadIdRules)->not->toHaveKey('pickup')
            ->and($payloadIdRules)->not->toHaveKey('dropoff');

        $podRules = FleetOpsPublicCreateOrderRequestProbe::create('/fleetops-test', 'POST', [
            'pod_required' => true,
        ])->rules();

        expect(ruleStrings($podRules['pod_method']))->toContain('required')
            ->and((new CreateOrderRequest())->attributes())->toBe([
                'pod_required' => 'proof of delivery required',
                'pod_method'   => 'proof of delivery method',
            ]);
    });

    test('internal create order request validates uuid payload contracts and messages', function () {
        app('config')->set('fleetops.pod_methods', ['scan', 'signature']);

        $baseRules = FleetOpsInternalCreateOrderRequestProbe::create('/fleetops-test', 'POST')->rules();

        expect($baseRules['order_config_uuid'])->toBe(['required'])
            ->and($baseRules['driver'])->toBe(['nullable', 'exists:drivers,uuid'])
            ->and($baseRules['service_quote'])->toBe(['nullable', 'exists:service_quotes,uuid'])
            ->and($baseRules['purchase_rate'])->toBe(['nullable', 'exists:purchase_rates,uuid'])
            ->and($baseRules['facilitator'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($baseRules['customer'][1])->toBeInstanceOf(ExistsInAny::class);

        $payloadRules = FleetOpsInternalCreateOrderRequestProbe::create('/fleetops-test', 'POST', [
            'payload' => [
                'entities' => [],
            ],
        ])->rules();

        expect($payloadRules['payload'])->toBe('required')
            ->and($payloadRules['payload.entities'])->toBe('array')
            ->and($payloadRules['payload.pickup_uuid'])->toBe('required')
            ->and($payloadRules['payload.dropoff_uuid'])->toBe('required')
            ->and($payloadRules['payload.waypoints'])->toBe('required|array|min:2')
            ->and($payloadRules['payload.return_uuid'])->toBe('nullable');

        $podRules = FleetOpsInternalCreateOrderRequestProbe::create('/fleetops-test', 'POST', [
            'order' => [
                'pod_required' => true,
            ],
        ])->rules();
        $request  = new InternalCreateOrderRequest();

        expect(ruleStrings($podRules['pod_method']))->toContain('required')
            ->and($request->messages())->toBe([
                'pod_method.required' => 'A proof of delivery method is required.',
            ])
            ->and($request->attributes())->toBe([
                'pod_required' => 'proof of delivery required',
                'pod_method'   => 'proof of delivery method',
            ]);
    });

    test('customer order request authorizes token sessions and limits customer order payload shape', function () {
        bindFleetOpsRequestSession();

        $request = CreateCustomerOrderRequest::create('/fleetops-test', 'POST');

        expect($request->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);

        expect($request->authorize())->toBeTrue();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $rules = $request->rules();

        expect($request->authorize())->toBeTrue()
            ->and($rules['type'])->toBe('nullable|string')
            ->and($rules['order_config'])->toBe('nullable|string')
            ->and($rules['scheduled_at'])->toBe('nullable|date')
            ->and($rules['notes'])->toBe('nullable|string|max:2000')
            ->and($rules['internal_id'])->toBe('nullable|string|max:191')
            ->and($rules['service_quote'])->toBe('nullable|string')
            ->and($rules['payload'])->toBe('nullable')
            ->and($rules['pickup'])->toBe('nullable')
            ->and($rules['dropoff'])->toBe('nullable')
            ->and($rules['return'])->toBe('nullable')
            ->and($rules['waypoints'])->toBe('nullable|array')
            ->and($rules['entities'])->toBe('nullable|array')
            ->and($rules['entities.*.currency'])->toBe('nullable|string|size:3')
            ->and($rules)->not->toHaveKeys(['customer', 'driver', 'vehicle', 'dispatch', 'status']);
    });

    test('service area request authorizes api credentials and changes border requirements by coordinates', function () {
        bindFleetOpsRequestSession();

        $unauthorized = CreateServiceAreaRequest::create('/fleetops-test', 'POST');

        expect($unauthorized->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $createRules     = CreateServiceAreaRequest::create('/fleetops-test', 'POST')->rules();
        $coordinateRules = CreateServiceAreaRequest::create('/fleetops-test', 'POST', [
            'latitude'  => 1.3521,
            'longitude' => 103.8198,
        ])->rules();
        $locationRules = CreateServiceAreaRequest::create('/fleetops-test', 'POST', [
            'location' => ['latitude' => 1.3521, 'longitude' => 103.8198],
        ])->rules();
        $patchRules = CreateServiceAreaRequest::create('/fleetops-test', 'PATCH')->rules();

        expect($unauthorized->authorize())->toBeTrue()
            ->and(ruleStrings($createRules['name']))->toContain('required', 'string')
            ->and(ruleStrings($createRules['country']))->toContain('required', 'string')
            ->and(ruleStrings($createRules['border']))->toContain('nullable', 'required')
            ->and(ruleStrings($coordinateRules['border']))->toContain('nullable')
            ->and(ruleStrings($coordinateRules['border']))->not->toContain('required')
            ->and(ruleStrings($locationRules['border']))->not->toContain('required')
            ->and(ruleStrings($patchRules['name']))->not->toContain('required')
            ->and(ruleStrings($patchRules['country']))->not->toContain('required')
            ->and($createRules['status'])->toBe('in:active,inactive')
            ->and(ruleStrings($createRules['parent']))->toContain('nullable', 'exists:service_areas,public_id')
            ->and($createRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($createRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
            ->and($createRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
            ->and($createRules['trigger_on_entry'])->toBe(['nullable', 'boolean'])
            ->and($createRules['trigger_on_exit'])->toBe(['nullable', 'boolean'])
            ->and($createRules['dwell_threshold_minutes'])->toBe(['nullable', 'integer', 'min:1', 'max:10080'])
            ->and($createRules['speed_limit_kmh'])->toBe(['nullable', 'integer', 'min:1', 'max:1000']);
    });

    test('place sensor and service rate requests expose conditional validation contracts', function () {
        $placeRules          = CreatePlaceRequest::create('/fleetops-test', 'POST')->rules();
        $coordinatePlaceRule = CreatePlaceRequest::create('/fleetops-test', 'POST', [
            'latitude'  => 1.3521,
            'longitude' => 103.8198,
        ])->rules();
        $sensorRules         = requestRules(CreateSensorRequest::class);
        $patchSensorRules    = requestRules(CreateSensorRequest::class, 'PATCH');
        $rateRules           = CreateServiceRateRequest::create('/fleetops-test', 'POST', [
            'rate_calculation_method'       => 'fixed_meter',
            'has_cod_fee'                   => true,
            'cod_calculation_method'        => 'flat',
            'has_peak_hours'                => true,
            'peak_hours_calculation_method' => 'percentage',
        ])->rules();

        expect(ruleStrings($placeRules['name']))->toContain('required')
            ->and(ruleStrings($placeRules['street1']))->toContain('required')
            ->and(ruleStrings($coordinatePlaceRule['name']))->toContain('nullable')
            ->and(ruleStrings($coordinatePlaceRule['street1']))->toContain('nullable')
            ->and($placeRules['customer'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($placeRules['contact'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($placeRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and(ruleStrings($sensorRules['name']))->toContain('required', 'string')
            ->and(ruleStrings($patchSensorRules['name']))->not->toContain('required')
            ->and($sensorRules['last_position'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($sensorRules['sensorable'])->toBe(['nullable', 'required_with:sensorable_type', 'string'])
            ->and($sensorRules['calibration'])->toBe(['nullable', 'array'])
            ->and(ruleStrings($rateRules['service_name']))->toContain('required', 'string')
            ->and(ruleStrings($rateRules['service_type']))->toContain('required', 'string')
            ->and(ruleStrings($rateRules['rate_calculation_method']))->toContain('required', 'string', 'in:fixed_meter,fixed_rate,per_meter,per_drop,algo,parcel')
            ->and(ruleStrings($rateRules['meter_fees']))->toContain('required', 'array')
            ->and($rateRules['algorithm'][1])->toBeInstanceOf(ComputableAlgo::class)
            ->and(ruleStrings($rateRules['cod_calculation_method']))->toContain('required', 'in:percentage,flat')
            ->and(ruleStrings($rateRules['cod_flat_fee']))->toContain('required', 'numeric')
            ->and(ruleStrings($rateRules['peak_hours_calculation_method']))->toContain('required', 'in:percentage,flat')
            ->and(ruleStrings($rateRules['peak_hours_percent']))->toContain('required', 'integer')
            ->and(ruleStrings($rateRules['peak_hours_start']))->toContain('required', 'date_format:H:i')
            ->and(ruleStrings($rateRules['peak_hours_end']))->toContain('required', 'date_format:H:i');
    });
}
