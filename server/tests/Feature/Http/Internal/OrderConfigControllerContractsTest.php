<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderConfigController;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Http\Request;

class FleetOpsInternalOrderConfigCreateRequestFake
{
    public array $rules;
    public array $validationResponses = [];

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function responseWithErrors($validator)
    {
        $this->validationResponses[] = $validator;

        return ['validation_errors' => true];
    }
}

class FleetOpsInternalOrderConfigValidatorFake
{
    public bool $fails;

    public function __construct(bool $fails)
    {
        $this->fails = $fails;
    }

    public function fails(): bool
    {
        return $this->fails;
    }
}

class FleetOpsInternalOrderConfigControllerCreateProbe extends OrderConfigController
{
    public FleetOpsInternalOrderConfigCreateRequestFake $fakeCreateRequest;
    public FleetOpsInternalOrderConfigValidatorFake $validator;
    public ?OrderConfig $createdRecord = null;
    public ?Throwable $createError     = null;
    public array $validatorPayloads    = [];
    public array $createRequests       = [];

    public function __construct()
    {
        $this->fakeCreateRequest = new FleetOpsInternalOrderConfigCreateRequestFake([
            'name' => ['required'],
            'key'  => ['required'],
        ]);
        $this->validator = new FleetOpsInternalOrderConfigValidatorFake(false);
    }

    protected function createOrderConfigRequest(Request $request)
    {
        return $this->fakeCreateRequest;
    }

    protected function makeOrderConfigValidator(Request $request, array $rules)
    {
        $this->validatorPayloads[] = [$request->input('orderConfig'), $rules];

        return $this->validator;
    }

    protected function createOrderConfigRecord(Request $request)
    {
        $this->createRequests[] = $request;

        if ($this->createError) {
            throw $this->createError;
        }

        return $this->createdRecord;
    }

    protected function createdOrderConfigResource($record): array
    {
        return ['order_config' => $record->uuid];
    }

    protected function errorResponse($message)
    {
        return ['error' => $message];
    }
}

function fleetopsInternalOrderConfigCreatePayload(): Request
{
    return new Request([
        'orderConfig' => [
            'name' => 'Same Day',
            'key'  => 'same-day',
        ],
    ]);
}

test('internal order config controller creates records from validated payloads', function () {
    $controller = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $record     = new OrderConfig();
    $record->setRawAttributes(['uuid' => 'order-config-uuid'], true);
    $controller->createdRecord = $record;

    $request  = fleetopsInternalOrderConfigCreatePayload();
    $response = $controller->createRecord($request);

    expect($response)->toBe(['order_config' => 'order-config-uuid'])
        ->and($controller->validatorPayloads)->toBe([
            [
                ['name' => 'Same Day', 'key' => 'same-day'],
                ['name' => ['required'], 'key' => ['required']],
            ],
        ])
        ->and($controller->createRequests)->toBe([$request]);
});

test('internal order config controller returns request validation responses before creating records', function () {
    $controller            = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $controller->validator = new FleetOpsInternalOrderConfigValidatorFake(true);

    $response = $controller->createRecord(fleetopsInternalOrderConfigCreatePayload());

    expect($response)->toBe(['validation_errors' => true])
        ->and($controller->createRequests)->toBe([])
        ->and($controller->fakeCreateRequest->validationResponses)->toBe([$controller->validator]);
});

test('internal order config controller converts model creation exceptions into error responses', function () {
    $controller              = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $controller->createError = new RuntimeException('Order config name already exists.');

    expect($controller->createRecord(fleetopsInternalOrderConfigCreatePayload()))->toBe([
        'error' => 'Order config name already exists.',
    ]);
});
