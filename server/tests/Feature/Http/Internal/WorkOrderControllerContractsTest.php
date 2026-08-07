<?php

use Fleetbase\FleetOps\Exports\WorkOrderExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\WorkOrderController;
use Fleetbase\FleetOps\Imports\WorkOrderImport;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsInternalWorkOrderExportRequestFake extends ExportRequest
{
    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }
}

class FleetOpsInternalWorkOrderImportRequestFake extends ImportRequest
{
    public array $resolvedFiles = [];

    public function resolveFilesFromIds(string $param = 'files')
    {
        return collect($this->resolvedFiles);
    }
}

class FleetOpsInternalWorkOrderImportFake extends WorkOrderImport
{
    public function __construct(int $imported)
    {
        $this->imported = $imported;
    }
}

class FleetOpsInternalWorkOrderControllerExportImportProbe extends WorkOrderController
{
    public array $downloads = [];
    public array $imports   = [];
    public array $imported  = [2, 4];
    public bool $failImport = false;

    protected function downloadExport(WorkOrderExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }

    protected function createImport(): WorkOrderImport
    {
        return new FleetOpsInternalWorkOrderImportFake(array_shift($this->imported) ?? 0);
    }

    protected function importFile(WorkOrderImport $import, string $path, string $disk): void
    {
        if ($this->failImport) {
            throw new RuntimeException('invalid work order import');
        }

        $this->imports[] = [$import->imported, $path, $disk];
    }
}

class FleetOpsInternalWorkOrderControllerEmailProbe extends WorkOrderController
{
    public ?WorkOrder $workOrder = null;
    public array $lookups        = [];
    public array $mail           = [];
    public array $activity       = [];

    protected function workOrderForEmail(string $id): WorkOrder
    {
        $this->lookups[] = $id;

        return $this->workOrder;
    }

    protected function sendWorkOrderDispatchedMail(string $email, WorkOrder $workOrder): void
    {
        $this->mail[] = [$email, $workOrder->public_id];
    }

    protected function recordWorkOrderSentActivity(WorkOrder $workOrder, string $email): void
    {
        $this->activity[] = [$workOrder->public_id, $email];
    }
}

function fleetopsInternalWorkOrderExportRequest(array $input): FleetOpsInternalWorkOrderExportRequestFake
{
    return FleetOpsInternalWorkOrderExportRequestFake::create('/internal/work-orders/export', 'POST', $input);
}

function fleetopsInternalWorkOrderImportRequest(array $input, array $files): FleetOpsInternalWorkOrderImportRequestFake
{
    $request                = FleetOpsInternalWorkOrderImportRequestFake::create('/internal/work-orders/import', 'POST', $input);
    $request->resolvedFiles = $files;

    return $request;
}

function fleetopsInternalWorkOrderForEmail(?object $assignee): WorkOrder
{
    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes(['public_id' => 'wo_public'], true);
    $workOrder->setRelation('assignee', $assignee);

    return $workOrder;
}

test('internal work order controller exports selected work orders', function () {
    $controller = new FleetOpsInternalWorkOrderControllerExportImportProbe();
    $response   = $controller->export(fleetopsInternalWorkOrderExportRequest([
        'format'     => 'csv',
        'selections' => ['wo_1', 'wo_2'],
    ]));

    expect($response['download'])->toStartWith('work-orders-')
        ->and($response['download'])->toEndWith('.csv')
        ->and($controller->downloads)->toHaveCount(1)
        ->and($controller->downloads[0][0])->toBeInstanceOf(WorkOrderExport::class)
        ->and($controller->downloads[0][1])->toBe($response['download'])
        ->and($response['headings'])->toContain('Subject', 'Assignee', 'Due At');
});

test('internal work order controller imports files and totals imported rows', function () {
    $controller = new FleetOpsInternalWorkOrderControllerExportImportProbe();
    $response   = $controller->import(fleetopsInternalWorkOrderImportRequest([
        'disk' => 'imports',
    ], [
        (object) ['path' => 'work-orders/a.csv'],
        (object) ['path' => 'work-orders/b.csv'],
    ]));

    expect($response->getData(true))->toBe([
        'status'   => 'ok',
        'message'  => 'Import completed',
        'imported' => 6,
    ])
        ->and($controller->imports)->toBe([
            [2, 'work-orders/a.csv', 'imports'],
            [4, 'work-orders/b.csv', 'imports'],
        ]);
});

test('internal work order controller reports invalid imports', function () {
    $controller             = new FleetOpsInternalWorkOrderControllerExportImportProbe();
    $controller->failImport = true;

    $response = $controller->import(fleetopsInternalWorkOrderImportRequest([], [
        (object) ['path' => 'work-orders/bad.csv'],
    ]));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe([
            'error' => 'Invalid file, unable to process.',
        ]);
});

test('internal work order controller sends work order emails and validates recipients', function () {
    $controller            = new FleetOpsInternalWorkOrderControllerEmailProbe();
    $controller->workOrder = fleetopsInternalWorkOrderForEmail(null);
    $missingAssignee       = $controller->sendEmail('wo_public');

    expect($missingAssignee->getStatusCode())->toBe(422)
        ->and($missingAssignee->getData(true))->toBe([
            'error' => 'This work order has no assigned vendor.',
        ]);

    $controller->workOrder = fleetopsInternalWorkOrderForEmail((object) ['email' => null]);
    $missingEmail          = $controller->sendEmail('wo_public');

    expect($missingEmail->getStatusCode())->toBe(422)
        ->and($missingEmail->getData(true))->toBe([
            'error' => 'The assigned vendor has no email address on file.',
        ]);

    $controller->workOrder = fleetopsInternalWorkOrderForEmail((object) ['email' => 'vendor@example.test']);
    $sent                  = $controller->sendEmail('wo_public');

    expect($sent->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'Work order successfully sent to vendor@example.test',
    ])
        ->and($controller->lookups)->toBe(['wo_public', 'wo_public', 'wo_public'])
        ->and($controller->mail)->toBe([['vendor@example.test', 'wo_public']])
        ->and($controller->activity)->toBe([['wo_public', 'vendor@example.test']]);
});
