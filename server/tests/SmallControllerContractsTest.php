<?php

use Fleetbase\FleetOps\Exports\SensorExport;
use Fleetbase\FleetOps\Exports\ServiceAreaExport;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\LabelController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\NavigatorController as PublicNavigatorController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderConfigController as PublicOrderConfigController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\GettingStartedController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderConfigController as InternalOrderConfigController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SensorController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceAreaController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ZoneController;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsSmallControllerExportRequestFake extends ExportRequest
{
    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }
}

class FleetOpsLabelControllerProbe extends LabelController
{
    public mixed $subject = null;
    public array $lookups = [];

    protected function findLabelSubject(?string $type, string $publicId): mixed
    {
        $this->lookups[] = [$type, $publicId];

        return $this->subject;
    }

    protected function apiError(string $message)
    {
        return ['error' => $message];
    }

    protected function makeResponse(string $text)
    {
        return ['text' => $text];
    }

    protected function jsonResponse(array $payload)
    {
        return ['json' => $payload];
    }
}

class FleetOpsLabelSubjectFake
{
    public array $streams = [];

    public function pdfLabelStream(): string
    {
        $this->streams[] = 'streamed';

        return 'pdf-stream';
    }

    public function pdfLabel(): object
    {
        return new class {
            public function output(): string
            {
                return 'label-output';
            }
        };
    }
}

class FleetOpsPublicOrderConfigControllerProbe extends PublicOrderConfigController
{
    public mixed $queryResults    = ['order-config-a'];
    public ?OrderConfig $resolved = null;
    public ?OrderConfig $found    = null;
    public bool $missing          = false;

    protected function queryOrderConfigs(Request $request)
    {
        return $this->queryResults;
    }

    protected function resolveOrderConfig(string $id): ?OrderConfig
    {
        return $this->resolved;
    }

    protected function findOrderConfigOrFail(string $id): OrderConfig
    {
        if ($this->missing) {
            throw new ModelNotFoundException();
        }

        return $this->found;
    }

    protected function orderConfigCollection($results)
    {
        return ['collection' => $results];
    }

    protected function orderConfigResource(OrderConfig $orderConfig)
    {
        return ['resource' => $orderConfig->uuid];
    }

    protected function apiError(string $message, int $status)
    {
        return ['error' => $message, 'status' => $status];
    }
}

class FleetOpsInternalOrderConfigControllerProbe extends InternalOrderConfigController
{
    public ?OrderConfig $orderConfig = null;
    public array $wrapped            = [];

    protected function findOrderConfig(string $id): ?OrderConfig
    {
        return $this->orderConfig;
    }

    protected function wrapResource(): void
    {
        $this->wrapped[] = $this->resourceSingularlName;
    }

    protected function deletedResource(OrderConfig $orderConfig)
    {
        return ['deleted' => $orderConfig->uuid];
    }

    protected function errorResponse(string $message)
    {
        return ['error' => $message];
    }
}

class FleetOpsSmallControllerOrderConfigFake extends OrderConfig
{
    public bool $deletedForTest = false;

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsSensorControllerProbe extends SensorController
{
    public array $downloads = [];

    protected function downloadExport(SensorExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }
}

class FleetOpsServiceAreaControllerProbe extends ServiceAreaController
{
    public static array $downloads = [];

    protected static function downloadExport(ServiceAreaExport $export, string $fileName)
    {
        static::$downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }
}

class FleetOpsControllerCustomFieldModelFake extends ServiceArea
{
    public array $synced = [];

    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        $this->synced[] = [$payload, $options];

        return $payload;
    }
}

class FleetOpsControllerZoneFake extends Zone
{
    public array $synced = [];

    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        $this->synced[] = [$payload, $options];

        return $payload;
    }
}

class FleetOpsGettingStartedControllerProbe extends GettingStartedController
{
    protected function getStatusForCompany($company): array
    {
        return ['company' => $company->uuid, 'done' => true];
    }

    protected function jsonResponse(array $payload)
    {
        return ['json' => $payload];
    }
}

class FleetOpsPublicNavigatorControllerProbe extends PublicNavigatorController
{
    public mixed $settings = ['require_photo' => true];
    public array $lookups  = [];

    protected function findCompanyByPublicId(string $companyId): ?Company
    {
        $company = new Company();
        $company->setRawAttributes(['uuid' => 'company-uuid', 'public_id' => $companyId], true);

        $this->lookups[] = $companyId;

        return $company;
    }

    protected function driverOnboardSetting(string $companyUuid): mixed
    {
        $this->lookups[] = $companyUuid;

        return $this->settings;
    }

    protected function jsonResponse(array $payload): JsonResponse
    {
        return new JsonResponse($payload);
    }
}

class FleetOpsContactControllerProbe extends ContactController
{
    public function createInput(Request $request): array
    {
        return $this->contactCreateInputFromRequest($request);
    }

    public function updateInput(Request $request): array
    {
        return $this->contactUpdateInputFromRequest($request);
    }
}

class FleetOpsSensorQueryRecorder
{
    public array $calls = [];

    public function with(array $relations): void
    {
        $this->calls[] = ['with', $relations];
    }
}

function fleetopsSmallExportRequest(array $input): FleetOpsSmallControllerExportRequestFake
{
    return FleetOpsSmallControllerExportRequestFake::create('/export', 'POST', $input);
}

function fleetopsSmallExportSelections(object $export): array
{
    $property = new ReflectionProperty($export, 'selections');
    $property->setAccessible(true);

    return $property->getValue($export);
}

test('label controller resolves subject type and returns stream text base64 or errors', function () {
    $controller          = new FleetOpsLabelControllerProbe();
    $controller->subject = new FleetOpsLabelSubjectFake();

    expect($controller->getLabel('order_123', new Request()))->toBe('pdf-stream')
        ->and($controller->lookups)->toBe([['order', 'order_123']])
        ->and($controller->getLabel('waypoint_123', new Request(['format' => 'text'])))->toBe(['text' => 'label-output'])
        ->and($controller->getLabel('entity_123', new Request(['format' => 'base64', 'type' => 'entity'])))->toBe([
            'json' => ['data' => base64_encode('label-output')],
        ]);

    $controller->subject = null;

    expect($controller->getLabel('unknown_123', new Request()))->toBe(['error' => 'Unable to render label.']);
});

test('public order config controller wraps query resolved fallback and missing results', function () {
    $resolved = new OrderConfig();
    $resolved->setRawAttributes(['uuid' => 'resolved-uuid'], true);
    $found = new OrderConfig();
    $found->setRawAttributes(['uuid' => 'found-uuid'], true);

    $controller           = new FleetOpsPublicOrderConfigControllerProbe();
    $controller->resolved = $resolved;

    expect($controller->query(new Request(['limit' => 2])))->toBe(['collection' => ['order-config-a']])
        ->and($controller->find('transport'))->toBe(['resource' => 'resolved-uuid']);

    $controller->resolved = null;
    $controller->found    = $found;

    expect($controller->find('fallback-id'))->toBe(['resource' => 'found-uuid']);

    $controller->missing = true;

    expect($controller->find('missing-id'))->toBe(['error' => 'Order config not found.', 'status' => 404]);
});

test('internal order config controller protects missing and core configs before deletion', function () {
    $controller = new FleetOpsInternalOrderConfigControllerProbe();

    expect($controller->deleteRecord('missing', new Request()))->toBe(['error' => 'No order config found.']);

    $core = new FleetOpsSmallControllerOrderConfigFake();
    $core->setRawAttributes(['uuid' => 'core-uuid', 'core_service' => 1], true);
    $controller->orderConfig = $core;

    expect($controller->deleteRecord('core-uuid', new Request()))->toBe(['error' => 'Core service order config\'s cannot be deleted.'])
        ->and($core->deletedForTest)->toBeFalse();

    $deletable = new FleetOpsSmallControllerOrderConfigFake();
    $deletable->setRawAttributes(['uuid' => 'delete-uuid', 'core_service' => 0], true);
    $controller->orderConfig = $deletable;

    expect($controller->deleteRecord('delete-uuid', new Request()))->toBe(['deleted' => 'delete-uuid'])
        ->and($deletable->deletedForTest)->toBeTrue()
        ->and($controller->wrapped)->toHaveCount(1);
});

test('sensor and service area controllers export selections and eager load query relations', function () {
    $sensor = new FleetOpsSensorControllerProbe();
    $query  = new FleetOpsSensorQueryRecorder();

    $sensorResponse = $sensor->export(fleetopsSmallExportRequest([
        'format'     => 'csv',
        'selections' => ['sensor-a', 'sensor-b'],
    ]));
    SensorController::onQueryRecord($query, new Request());

    FleetOpsServiceAreaControllerProbe::$downloads = [];
    $serviceAreaResponse                           = FleetOpsServiceAreaControllerProbe::export(fleetopsSmallExportRequest([
        'format'     => 'xlsx',
        'selections' => ['area-a'],
    ]));

    expect($sensorResponse['download'])->toMatch('/^sensors-[0-9-]+\\.csv$/')
        ->and(fleetopsSmallExportSelections($sensor->downloads[0][0]))->toBe(['sensor-a', 'sensor-b'])
        ->and($query->calls)->toBe([['with', ['telematic', 'device', 'warranty']]])
        ->and($serviceAreaResponse['download'])->toMatch('/^service-areas-[0-9-]+\\.xlsx$/')
        ->and(fleetopsSmallExportSelections(FleetOpsServiceAreaControllerProbe::$downloads[0][0]))->toBe(['area-a']);
});

test('service area zone and getting started controllers expose small lifecycle contracts', function () {
    $serviceArea = new FleetOpsControllerCustomFieldModelFake();
    (new ServiceAreaController())->afterSave(new Request([
        'service_area' => ['custom_field_values' => [['key' => 'area_code', 'value' => 'A1']]],
    ]), $serviceArea);
    (new ServiceAreaController())->afterSave(new Request(['service_area' => ['custom_field_values' => []]]), $serviceArea);

    $zone = new FleetOpsControllerZoneFake();
    (new ZoneController())->afterSave(new Request([
        'zone' => ['custom_field_values' => [['key' => 'dock', 'value' => 'D1']]],
    ]), $zone);
    (new ZoneController())->afterSave(new Request(['zone' => ['custom_field_values' => []]]), $zone);

    $request = new Request();
    $request->setUserResolver(fn () => (object) ['company' => (object) ['uuid' => 'company-uuid']]);

    expect($serviceArea->synced)->toBe([[[['key' => 'area_code', 'value' => 'A1']], []]])
        ->and($zone->synced)->toBe([[[['key' => 'dock', 'value' => 'D1']], []]])
        ->and((new FleetOpsGettingStartedControllerProbe())->status($request))->toBe([
            'json' => ['company' => 'company-uuid', 'done' => true],
        ]);
});

test('public navigator controller returns configured or default driver onboarding settings', function () {
    $controller = new FleetOpsPublicNavigatorControllerProbe();

    $configured = $controller->getDriverOnboardSettings('company_public');

    $controller->settings = null;
    $defaults             = $controller->getDriverOnboardSettings('company_public');

    expect($configured->getData(true))->toBe(['driverOnboardSettings' => ['require_photo' => true]])
        ->and($defaults->getData(true))->toBe(['driverOnboardSettings' => []])
        ->and($controller->lookups)->toBe([
            'company_public',
            'company-uuid',
            'company_public',
            'company-uuid',
        ]);
});

test('public contact controller normalizes create input and preserves update fields', function () {
    $controller = new FleetOpsContactControllerProbe();

    expect($controller->createInput(new Request([
        'name'  => 'Dispatch Desk',
        'title' => 'Ops',
        'email' => 'ops@example.test',
        'phone' => '+1 (555) 010-2000',
        'meta'  => ['region' => 'west'],
    ])))->toMatchArray([
        'name'  => 'Dispatch Desk',
        'title' => 'Ops',
        'email' => 'ops@example.test',
        'type'  => 'contact',
        'meta'  => ['region' => 'west'],
    ])
        ->and($controller->createInput(new Request([
            'name'  => 'Customer',
            'type'  => 'customer',
            'phone' => ['raw' => '+15550102000'],
        ])))->toMatchArray([
            'name'  => 'Customer',
            'type'  => 'customer',
            'phone' => ['raw' => '+15550102000'],
        ])
        ->and($controller->updateInput(new Request([
            'name'       => 'Updated',
            'type'       => 'contact',
            'title'      => 'Lead',
            'email'      => 'lead@example.test',
            'phone'      => '+65 8123 4567',
            'meta'       => ['vip' => true],
            'place_uuid' => 'ignored',
        ])))->toBe([
            'name'  => 'Updated',
            'type'  => 'contact',
            'title' => 'Lead',
            'email' => 'lead@example.test',
            'phone' => '+65 8123 4567',
            'meta'  => ['vip' => true],
        ]);
});
