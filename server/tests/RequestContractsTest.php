<?php

namespace Illuminate\Validation {
    if (!class_exists(Rule::class)) {
        class Rule
        {
            /**
             * Constraints recorded from `where()` closures, so tests can assert
             * how a unique rule scopes its lookup.
             *
             * @var array<int, array<int, mixed>>
             */
            public array $constraints = [];

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
                // The real rule defers the closure to validation time against a
                // query builder; run it now against a recorder so the scoping
                // logic inside it is exercised rather than discarded
                if ($callback instanceof \Closure) {
                    $callback(new class($this) {
                        public function __construct(private Rule $rule)
                        {
                        }

                        public function where($column, $value = null): self
                        {
                            $this->rule->constraints[] = ['where', $column, $value];

                            return $this;
                        }

                        public function whereNull($column): self
                        {
                            $this->rule->constraints[] = ['whereNull', $column];

                            return $this;
                        }
                    });
                }

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

namespace Illuminate\Validation\Rules {
    if (!class_exists(RequiredIf::class)) {
        class RequiredIf
        {
            public function __construct(private bool $condition)
            {
            }

            public function __toString(): string
            {
                return $this->condition ? 'required' : 'nullable';
            }
        }
    }
}

namespace {
    use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
    use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateContactRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateEntityRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateEquipmentRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFleetRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateIssueRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\CreatePartRequest;
    use Fleetbase\FleetOps\Http\Requests\CreatePayloadRequest;
    use Fleetbase\FleetOps\Http\Requests\CreatePlaceRequest;
    use Fleetbase\FleetOps\Http\Requests\CreatePurchaseRateRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateSensorRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateServiceAreaRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateServiceQuoteRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateServiceRateRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateTrackingNumberRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateTrackingStatusRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateVendorRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\CreateZoneRequest;
    use Fleetbase\FleetOps\Http\Requests\DecodeTrackingNumberQR;
    use Fleetbase\FleetOps\Http\Requests\DriverSimulationRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\AssignOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\CreateDriverRequest as InternalCreateDriverRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderConfigRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderRequest as InternalCreateOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\FleetActionRequest;
    use Fleetbase\FleetOps\Http\Requests\Internal\UpdateDriverRequest as InternalUpdateDriverRequest;
    use Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest;
    use Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateDeviceRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateIssueRequest;
    use Fleetbase\FleetOps\Http\Requests\UpdateWorkOrderRequest;
    use Fleetbase\FleetOps\Http\Requests\VerifyCreateCustomerRequest;
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

    class FleetOpsInternalUpdateDriverRequestProbe extends InternalUpdateDriverRequest
    {
        public bool $canUpdate = false;

        protected function canUpdateDriver(): bool
        {
            return $this->canUpdate;
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
        $internalDriverRules   = requestRules(InternalUpdateDriverRequest::class, 'PATCH');

        expect($vehicleRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($vehicleRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
            ->and($vehicleRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
            ->and(ruleStrings($vehicleRules['status']))->toContain('nullable')
            ->and(implode('|', ruleStrings($vehicleRules['status'])))->toContain('operational')
            ->and($fuelReportRules['driver'])->toBe(['required'])
            ->and($fuelReportRules['odometer'])->toBe(['required'])
            ->and($fuelReportRules['volume'])->toBe(['required'])
            ->and($fuelReportUpdateRules['driver'])->toBe(['required'])
            ->and($internalDriverRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($internalDriverRules['vehicle'][1])->toBeInstanceOf(ResolvableVehicle::class)
            ->and($internalDriverRules['latitude'])->toBe(['nullable', 'required_with:longitude', 'numeric'])
            ->and($internalDriverRules['longitude'])->toBe(['nullable', 'required_with:latitude', 'numeric']);
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

    test('contact and tracking number create requests expose authorization and validation contracts', function () {
        bindFleetOpsRequestSession();
        expect(CreateContactRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse()
            ->and(CreateTrackingNumberRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['storefront_key' => 'storefront-key']);
        expect(CreateContactRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);
        expect(CreateContactRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue()
            ->and(CreateTrackingNumberRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue();

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);
        expect(CreateContactRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue();

        $contactCreateRules = requestRules(CreateContactRequest::class);
        $contactPatchRules  = requestRules(CreateContactRequest::class, 'PATCH');
        $trackingRules      = requestRules(CreateTrackingNumberRequest::class);

        expect(ruleStrings($contactCreateRules['name']))->toContain('required')
            ->and(ruleStrings($contactCreateRules['type']))->toContain('required')
            ->and(ruleStrings($contactPatchRules['name']))->not->toContain('required')
            ->and(ruleStrings($contactPatchRules['type']))->not->toContain('required')
            ->and($contactCreateRules['email'])->toBe(['nullable', 'email'])
            ->and($contactCreateRules['phone'])->toBe(['nullable'])
            ->and($trackingRules['region'])->toBe('required|string')
            ->and($trackingRules['owner'][0])->toBe('required')
            ->and($trackingRules['owner'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($trackingRules['type'])->toBe('nullable|in:city,province,country')
            ->and($trackingRules['status'])->toBe('nullable|in:active,inactive');
    });

    test('purchase scheduling fleet and simple action requests expose authorization and rules', function () {
        bindFleetOpsRequestSession();

        expect(CancelOrderRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse()
            ->and(CreatePurchaseRateRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse()
            ->and(ScheduleOrderRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse()
            ->and(CreateFleetRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse()
            ->and(CreateServiceQuoteRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        expect(CancelOrderRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue()
            ->and(CreatePurchaseRateRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue()
            ->and(ScheduleOrderRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue()
            ->and(CreateFleetRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue()
            ->and(DecodeTrackingNumberQR::create('/fleetops-test', 'POST')->authorize())->toBeTrue();

        $purchaseRateRules = requestRules(CreatePurchaseRateRequest::class);
        $scheduleRules     = requestRules(ScheduleOrderRequest::class);
        $fleetCreateRules  = requestRules(CreateFleetRequest::class);
        $fleetPatchRules   = requestRules(CreateFleetRequest::class, 'PATCH');

        expect(ruleStrings($purchaseRateRules['service_quote']))->toContain('required')
            ->and(ruleStrings($purchaseRateRules['service_quote']))->toContain('exists:service_quotes,public_id')
            ->and(ruleStrings($purchaseRateRules['order']))->toContain('nullable')
            ->and(ruleStrings($purchaseRateRules['order']))->toContain('exists:orders,public_id')
            ->and($purchaseRateRules['customer'][0])->toBe('nullable')
            ->and($purchaseRateRules['customer'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($scheduleRules)->toBe([
                'date'     => 'required|date_format:Y-m-d',
                'time'     => 'nullable',
                'timezone' => 'nullable|timezone',
            ])
            ->and(ruleStrings($fleetCreateRules['name']))->toContain('required')
            ->and(ruleStrings($fleetPatchRules['name']))->not->toContain('required')
            ->and($fleetCreateRules['service_area'])->toBe('exists:service_areas,public_id')
            ->and(requestRules(CancelOrderRequest::class))->toBe(['order' => 'required|exists:orders,uuid'])
            ->and(requestRules(DecodeTrackingNumberQR::class))->toBe(['code' => 'required|string'])
            ->and(requestRules(CreateServiceQuoteRequest::class))->toBe([]);
    });

    test('internal assign order request requires company session and order driver references', function () {
        session(['company' => null]);
        expect(AssignOrderRequest::create('/fleetops-test', 'POST')->authorize())->toBeFalse();

        session(['company' => 'company-uuid']);

        expect(AssignOrderRequest::create('/fleetops-test', 'POST')->authorize())->toBeTrue()
            ->and(requestRules(AssignOrderRequest::class))->toBe([
                'order'  => ['required', 'exists:orders,public_id'],
                'driver' => ['required', 'exists:drivers,public_id'],
            ]);
    });

    test('internal create driver request exposes user identity vehicle and message contracts', function () {
        $createRules = InternalCreateDriverRequest::create('/fleetops-test', 'POST')->rules();
        $userRules   = InternalCreateDriverRequest::create('/fleetops-test', 'POST', [
            'driver' => ['user_uuid' => 'user_uuid'],
        ])->rules();
        $patchRules  = InternalCreateDriverRequest::create('/fleetops-test', 'PATCH')->rules();
        $request     = new InternalCreateDriverRequest();
        $updateProbe = new FleetOpsInternalUpdateDriverRequestProbe();

        expect(ruleStrings($createRules['name']))->toContain('required', 'nullable', 'string', 'max:255')
            ->and(ruleStrings($createRules['email']))->toContain('required')
            ->and(ruleStrings($createRules['phone']))->toContain('required')
            ->and(ruleStrings($userRules['name']))->not->toContain('required')
            ->and(ruleStrings($patchRules['name']))->not->toContain('required')
            ->and($updateProbe->authorize())->toBeFalse();

        $updateProbe->canUpdate = true;

        expect($updateProbe->authorize())->toBeTrue()
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

    test('customer creation request authorizes token sessions and protects identity contracts', function () {
        bindFleetOpsRequestSession();

        $request = CreateCustomerRequest::create('/fleetops-test', 'POST');

        expect($request->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);

        expect($request->authorize())->toBeTrue();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid', 'company' => 'company-uuid']);

        $rules = $request->rules();

        expect($request->authorize())->toBeTrue()
            ->and($rules['identity'])->toBe('required|string')
            ->and($rules['code'])->toBe('required|string')
            ->and($rules['name'])->toBe('required|string')
            ->and($rules['password'])->toBe('required|string|min:8')
            ->and(ruleStrings($rules['email']))->toContain('email', 'nullable', 'unique:contacts')
            ->and(ruleStrings($rules['phone']))->toContain('nullable', 'string', 'unique:contacts')
            ->and($rules['meta'])->toBe('nullable|array');

        // Both uniqueness rules scope their lookup to the session company and
        // ignore soft-deleted contacts, so a customer may reuse an email or
        // phone freed up by a deleted record or belonging to another company
        foreach (['email', 'phone'] as $field) {
            $uniqueRule = collect($rules[$field])->first(fn ($rule) => $rule instanceof Illuminate\Validation\Rule);

            expect($uniqueRule)->not->toBeNull()
                ->and($uniqueRule->constraints)->toBe([
                    ['where', 'company_uuid', 'company-uuid'],
                    ['whereNull', 'deleted_at'],
                ]);
        }
    });

    test('part request authorizes token sessions and exposes inventory metadata rules', function () {
        bindFleetOpsRequestSession();

        $request = CreatePartRequest::create('/fleetops-test', 'POST');

        expect($request->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $createRules = $request->rules();
        $patchRules  = CreatePartRequest::create('/fleetops-test', 'PATCH')->rules();

        expect($request->authorize())->toBeTrue()
            ->and($createRules['sku'])->toBe(['nullable', 'string'])
            ->and(ruleStrings($createRules['name']))->toContain('required', 'string')
            ->and(ruleStrings($patchRules['name']))->not->toContain('required')
            ->and($createRules['manufacturer'])->toBe(['nullable', 'string'])
            ->and($createRules['model'])->toBe(['nullable', 'string'])
            ->and($createRules['serial_number'])->toBe(['nullable', 'string'])
            ->and($createRules['barcode'])->toBe(['nullable', 'string'])
            ->and($createRules['quantity_on_hand'])->toBe(['nullable', 'integer', 'min:0'])
            ->and($createRules['currency'])->toBe(['nullable', 'string', 'size:3'])
            ->and($createRules['asset'])->toBe(['nullable', 'required_with:asset_type', 'string'])
            ->and($createRules['vendor'])->toBe(['nullable', 'string'])
            ->and($createRules['warranty'])->toBe(['nullable', 'string'])
            ->and($createRules['photo'])->toBe(['nullable', 'string'])
            ->and($createRules['specs'])->toBe(['nullable', 'array'])
            ->and($createRules['meta'])->toBe(['nullable', 'array']);

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);

        expect($request->authorize())->toBeTrue();
    });

    test('entity equipment payload and simulation requests expose conditional contracts', function () {
        bindFleetOpsRequestSession();

        $entityRequest    = CreateEntityRequest::create('/fleetops-test', 'POST', ['weight' => 12, 'length' => 4, 'width' => 5, 'height' => 6, 'declared_value' => 8, 'price' => 9, 'sales_price' => 10]);
        $equipmentRequest = CreateEquipmentRequest::create('/fleetops-test', 'POST');
        $payloadRequest   = CreatePayloadRequest::create('/fleetops-test', 'POST', ['cod_amount' => 30]);
        $simulationRules  = DriverSimulationRequest::create('/fleetops-test', 'POST')->rules();

        expect($entityRequest->authorize())->toBeFalse()
            ->and($equipmentRequest->authorize())->toBeFalse()
            ->and($payloadRequest->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $entityRules    = $entityRequest->rules();
        $equipmentRules = $equipmentRequest->rules();
        $payloadRules   = $payloadRequest->rules();

        expect($entityRequest->authorize())->toBeTrue()
            ->and(ruleStrings($entityRules['name']))->toContain('required')
            ->and(ruleStrings($entityRules['type']))->toContain('required')
            ->and(ruleStrings($entityRules['destination']))->toContain('nullable', 'exists:places,public_id')
            ->and(ruleStrings($entityRules['payload']))->toContain('nullable', 'exists:payloads,public_id', 'required_with:destination,waypoint')
            ->and(ruleStrings($entityRules['weight_unit']))->toContain('required', 'in:g,oz,lb,kg')
            ->and(ruleStrings($entityRules['dimensions_unit']))->toContain('required', 'in:cm,in,ft,mm,m,yd')
            ->and(ruleStrings($entityRules['currency']))->toContain('required', 'size:3')
            ->and($equipmentRequest->authorize())->toBeTrue()
            ->and(ruleStrings($equipmentRules['name']))->toContain('required', 'string')
            ->and($equipmentRules['equipable'])->toBe(['nullable', 'required_with:equipable_type', 'string'])
            ->and($equipmentRules['meta'])->toBe(['nullable', 'array'])
            ->and($payloadRequest->authorize())->toBeTrue()
            ->and(ruleStrings($payloadRules['type']))->toContain('required')
            ->and(ruleStrings($payloadRules['cod_currency']))->toContain('required', 'size:3')
            ->and(ruleStrings($payloadRules['cod_payment_method']))->toContain('required', 'in:card,check,cash,bank_transfer')
            ->and($payloadRules['pickup'])->toBe('required')
            ->and($payloadRules['dropoff'])->toBe('required')
            ->and($payloadRules['waypoints'])->toBe('required|array|min:2')
            ->and($simulationRules['start'][0])->toBe('required')
            ->and($simulationRules['start'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($simulationRules['end'][0])->toBe('required')
            ->and($simulationRules['end'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and(ruleStrings($simulationRules['order']))->toContain('nullable', 'string', 'exists:orders,public_id');

        $payloadWithWaypointRules = CreatePayloadRequest::create('/fleetops-test', 'POST', [
            'pickup'  => 'place_pickup',
            'dropoff' => 'place_dropoff',
        ])->rules();
        $orderSimulationRules = DriverSimulationRequest::create('/fleetops-test', 'POST', [
            'action' => 'order',
        ])->rules();

        expect($payloadWithWaypointRules['pickup'])->toBe('required|exists:places,public_id')
            ->and($payloadWithWaypointRules['dropoff'])->toBe('required|exists:places,public_id')
            ->and($payloadWithWaypointRules['waypoints'])->toBe('array')
            ->and($orderSimulationRules['start'][0])->toBe('required')
            ->and(ruleStrings($orderSimulationRules['order']))->toContain('required', 'string', 'exists:orders,public_id');

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);

        expect($entityRequest->authorize())->toBeTrue()
            ->and($equipmentRequest->authorize())->toBeTrue()
            ->and($payloadRequest->authorize())->toBeFalse();
    });

    test('driver request authorizes api sanctum and navigator sessions with identity rules', function () {
        bindFleetOpsRequestSession();

        $request = CreateDriverRequest::create('/fleetops-test', 'POST', [
            'email' => 'driver@example.test',
        ]);

        expect($request->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $createRules = $request->rules();
        $patchRules  = CreateDriverRequest::create('/fleetops-test', 'PATCH')->rules();

        expect($request->authorize())->toBeTrue()
            ->and(ruleStrings($createRules['name']))->toContain('required')
            ->and(ruleStrings($patchRules['name']))->not->toContain('required')
            ->and(ruleStrings($createRules['email']))->toContain('required', 'email', 'unique:users')
            ->and(ruleStrings($createRules['phone']))->toContain('required', 'unique:users')
            ->and($createRules['password'])->toBe('nullable|string')
            ->and($createRules['country'])->toBe('nullable|size:2')
            ->and($createRules['vehicle'])->toBe('nullable|string|starts_with:vehicle_|exists:vehicles,public_id')
            ->and($createRules['license_expiry'])->toBe('nullable|date')
            ->and($createRules['status'])->toBe('nullable|string|in:active,available,inactive')
            ->and($createRules['vendor'])->toBe('nullable|exists:vendors,public_id')
            ->and($createRules['job'])->toBe('nullable|exists:orders,public_id')
            ->and($createRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($createRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
            ->and($createRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
            ->and($request->attributes())->toBe([
                'email' => 'email address',
                'phone' => 'phone number',
            ]);

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);

        expect($request->authorize())->toBeTrue();

        $session = app('session.store');
        $session->flush();
        $navigatorRequest = Illuminate\Http\Request::create('/navigator/v1/drivers', 'POST');
        $navigatorRequest->setLaravelSession($session);
        app()->instance('request', $navigatorRequest);

        expect(CreateDriverRequest::create('/navigator/v1/drivers', 'POST')->authorize())->toBeTrue();
    });

    test('zone request authorizes api sessions and balances border coordinate rules', function () {
        bindFleetOpsRequestSession();

        $request = CreateZoneRequest::create('/fleetops-test', 'POST');

        expect($request->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $createRules        = $request->rules();
        $coordinateRules    = CreateZoneRequest::create('/fleetops-test', 'POST', ['latitude' => 1, 'longitude' => 2])->rules();
        $locationRules      = CreateZoneRequest::create('/fleetops-test', 'POST', ['location' => 'Empire State Building'])->rules();
        $patchRules         = CreateZoneRequest::create('/fleetops-test', 'PATCH')->rules();

        expect($request->authorize())->toBeTrue()
            ->and(ruleStrings($createRules['name']))->toContain('required', 'string')
            ->and(ruleStrings($createRules['service_area']))->toContain('required', 'exists:service_areas,public_id')
            ->and(ruleStrings($createRules['border']))->toContain('nullable', 'required')
            ->and(ruleStrings($coordinateRules['border']))->toContain('nullable')
            ->and(ruleStrings($coordinateRules['border']))->not->toContain('required')
            ->and(ruleStrings($locationRules['border']))->not->toContain('required')
            ->and(ruleStrings($patchRules['name']))->not->toContain('required')
            ->and($createRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
            ->and($createRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
            ->and($createRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
            ->and($createRules['status'])->toBe(['nullable', 'in:active,inactive'])
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

        // A street address alone satisfies the name requirement on create, and
        // updates never require either field since the record already exists
        $streetOnlyPlaceRules = CreatePlaceRequest::create('/fleetops-test', 'POST', ['street1' => '1 Marina Boulevard'])->rules();
        $updatePlaceRules     = CreatePlaceRequest::create('/fleetops-test', 'PUT')->rules();

        expect(ruleStrings($streetOnlyPlaceRules['name']))->toContain('nullable')
            ->and(ruleStrings($streetOnlyPlaceRules['street1']))->toContain('required')
            ->and(ruleStrings($updatePlaceRules['name']))->toContain('nullable')
            ->and(ruleStrings($updatePlaceRules['street1']))->toContain('nullable');
    });

    test('issue vendor dispatch and verification requests expose session authorization and validation contracts', function () {
        bindFleetOpsRequestSession();

        $issueRequest       = CreateIssueRequest::create('/fleetops-test', 'POST');
        $vendorRequest      = CreateVendorRequest::create('/fleetops-test', 'POST');
        $verification       = VerifyCreateCustomerRequest::create('/fleetops-test', 'POST');
        $dispatchRequest    = BulkDispatchRequest::create('/fleetops-test', 'POST');
        $unauthorizedUpdate = UpdateIssueRequest::create('/fleetops-test', 'PATCH');
        $dispatchRequest->setLaravelSession(app('session.store'));

        expect($issueRequest->authorize())->toBeFalse()
            ->and($vendorRequest->authorize())->toBeFalse()
            ->and($verification->authorize())->toBeFalse()
            ->and($dispatchRequest->authorize())->toBeFalse()
            ->and($unauthorizedUpdate->authorize())->toBeFalse();

        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid']);

        $issueRules        = $issueRequest->rules();
        $updateRules       = $unauthorizedUpdate->rules();
        $vendorCreateRules = $vendorRequest->rules();
        $vendorPatchRules  = CreateVendorRequest::create('/fleetops-test', 'PATCH')->rules();
        $verificationRules = $verification->rules();
        $dispatchRules     = $dispatchRequest->rules();

        expect($issueRequest->authorize())->toBeTrue()
            ->and($vendorRequest->authorize())->toBeTrue()
            ->and($verification->authorize())->toBeTrue()
            ->and($dispatchRequest->authorize())->toBeFalse()
            ->and($issueRules['driver'])->toBe(['required'])
            ->and($issueRules['location'])->toBe(['required'])
            ->and($issueRules['report'])->toBe(['required'])
            ->and($issueRules['tags'])->toBe(['nullable', 'array'])
            ->and($issueRules['tags.*'])->toBe(['string'])
            ->and($updateRules)->not->toHaveKeys(['driver', 'location'])
            ->and($updateRules['report'])->toBe(['required'])
            ->and(ruleStrings($vendorCreateRules['name']))->toContain('required', 'string')
            ->and(ruleStrings($vendorCreateRules['type']))->toContain('required', 'string')
            ->and(ruleStrings($vendorPatchRules['name']))->not->toContain('required')
            ->and($vendorCreateRules['email'])->toBe('nullable|email')
            ->and($vendorCreateRules['address'])->toBe('nullable|exists:places,public_id')
            ->and($verificationRules)->toBe([
                'mode'     => 'required|in:email,sms',
                'identity' => 'required|string',
                'name'     => 'nullable|string|max:255',
                'phone'    => 'nullable|string|max:32',
            ])
            ->and($dispatchRules['ids'])->toBe(['required', 'array'])
            ->and($dispatchRequest->messages())->toBe([
                'ids.required' => 'Please provide a resource ID.',
                'ids.array'    => 'Please provide multiple resource ID\'s.',
            ]);

        bindFleetOpsRequestSession(['user' => 'user-uuid']);

        expect($dispatchRequest->authorize())->toBeTrue();

        bindFleetOpsRequestSession(['is_sanctum_token' => true]);

        expect($issueRequest->authorize())->toBeTrue()
            ->and($verification->authorize())->toBeTrue()
            ->and($vendorRequest->authorize())->toBeFalse();
    });

    test('service quote fleet action and order config requests expose rule contracts', function () {
        bindFleetOpsRequestSession(['api_credential' => 'credential-uuid', 'company' => 'company-uuid']);

        $quoteRequest       = QueryServiceQuotesRequest::create('/fleetops-test', 'POST');
        $quoteRules         = $quoteRequest->rules();
        $fleetActionRules   = FleetActionRequest::create('/fleetops-test', 'POST')->rules();
        $orderConfigRules   = CreateOrderConfigRequest::create('/fleetops-test', 'POST')->rules();

        expect($quoteRequest->authorize())->toBeTrue()
            ->and(ruleStrings($quoteRules['payload']))->toContain('nullable', 'required_without_all:waypoints,pickup,dropoff', 'exists:payloads,public_id')
            ->and(ruleStrings($quoteRules['service_type']))->toContain('nullable', 'exists:service_rates,service_type')
            ->and($quoteRules['pickup'])->toBe(['nullable', 'required_without_all:payload,waypoints'])
            ->and($quoteRules['dropoff'])->toBe(['nullable', 'required_without_all:payload,waypoints'])
            ->and($quoteRules['waypoints'])->toBe(['nullable', 'array', 'required_without_all:payload,pickup,dropoff'])
            ->and($quoteRules['facilitator'][1])->toBeInstanceOf(ExistsInAny::class)
            ->and($quoteRules['currency'])->toBe(['nullable', 'string', 'size:3'])
            ->and($quoteRules['scheduled_at'])->toBe(['nullable', 'date'])
            ->and($fleetActionRules)->toBe([
                'fleet'   => 'string|exists:fleets,uuid',
                'driver'  => 'nullable|string|exists:drivers,uuid',
                'vehicle' => 'nullable|string|exists:vehicles,uuid',
            ])
            ->and(ruleStrings($orderConfigRules['name']))->toContain('required', 'unique:order_configs,name')
            ->and(ruleStrings($orderConfigRules['key']))->toContain('required', 'unique:order_configs,key');

        bindFleetOpsRequestSession();

        expect($quoteRequest->authorize())->toBeFalse();
    });
}
