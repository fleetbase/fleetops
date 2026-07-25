<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ManifestController;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Illuminate\Http\Request;

class FleetOpsManifestControllerProbe extends ManifestController
{
    public FleetOpsManifestQueryFake $companyQuery;
    public FleetOpsManifestQueryFake $manifestQuery;
    public FleetOpsManifestQueryFake $stopQuery;

    protected function manifestQueryForCompany(?string $companyUuid)
    {
        $this->companyQuery = new FleetOpsManifestQueryFake(['company' => $companyUuid]);

        return $this->companyQuery;
    }

    protected function manifestQueryByPublicId(string $id)
    {
        $this->manifestQuery = new FleetOpsManifestQueryFake(['public_id' => $id]);

        return $this->manifestQuery;
    }

    protected function manifestStopQueryByPublicId(string $id)
    {
        $this->stopQuery = new FleetOpsManifestQueryFake(['public_id' => $id], fleetopsManifestStopFake());

        return $this->stopQuery;
    }
}

class FleetOpsManifestQueryFake
{
    public array $calls = [];

    public function __construct(public array $scope = [], public mixed $record = null)
    {
        $this->record = $record ?? fleetopsManifestFake();
    }

    public function with(array $relations): self
    {
        $this->calls[] = ['with', array_keys($relations) === range(0, count($relations) - 1) ? $relations : array_keys($relations)];

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        $this->calls[] = ['where', $column, $value];

        return $this;
    }

    public function whereHas(string $relation, Closure $callback): self
    {
        $nested = new self();
        $callback($nested);
        $this->calls[] = ['whereHas', $relation, $nested->calls];

        return $this;
    }

    public function whereDate(string $column, mixed $value): self
    {
        $this->calls[] = ['whereDate', $column, $value];

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        $this->calls[] = ['orderByDesc', $column];

        return $this;
    }

    public function paginate(mixed $limit): array
    {
        $this->calls[] = ['paginate', $limit];

        return ['items' => [['public_id' => 'manifest_public']], 'limit' => $limit];
    }

    public function firstOrFail(): mixed
    {
        $this->calls[] = ['firstOrFail'];

        return $this->record;
    }
}

class FleetOpsManifestFake extends Manifest
{
    public bool $cancelledForTest = false;
    public bool $deletedForTest   = false;

    public function cancel(): self
    {
        $this->cancelledForTest = true;
        $this->setAttribute('status', 'cancelled');

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }

    public function toArray(): array
    {
        return $this->getAttributes();
    }
}

class FleetOpsManifestStopFake extends ManifestStop
{
    public array $updates = [];
    public array $freshes = [];

    public function markArrived(): self
    {
        $this->setAttribute('status', 'arrived');

        return $this;
    }

    public function markCompleted(): self
    {
        $this->setAttribute('status', 'completed');

        return $this;
    }

    public function markSkipped(): self
    {
        $this->setAttribute('status', 'skipped');

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function fresh($with = [])
    {
        $this->freshes[] = $with;

        return $this;
    }

    public function toArray(): array
    {
        return $this->getAttributes();
    }
}

function fleetopsManifestFake(): FleetOpsManifestFake
{
    $manifest = new FleetOpsManifestFake();
    $manifest->setRawAttributes(['uuid' => 'manifest-uuid', 'public_id' => 'manifest_public', 'status' => 'active'], true);

    return $manifest;
}

function fleetopsManifestStopFake(): FleetOpsManifestStopFake
{
    $stop = new FleetOpsManifestStopFake();
    $stop->setRawAttributes(['uuid' => 'stop-uuid', 'public_id' => 'stop_public', 'status' => 'pending'], true);

    return $stop;
}

test('manifest controller applies index filters and returns paginated results', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsManifestControllerProbe();
    $response   = $controller->index(new Request([
        'status'         => 'active',
        'driver_id'      => 'driver_public',
        'vehicle_id'     => 'vehicle_public',
        'scheduled_date' => '2026-05-01',
        'limit'          => 15,
    ]));

    expect($response->getData(true))->toBe([
        'items' => [['public_id' => 'manifest_public']],
        'limit' => 15,
    ])->and($controller->companyQuery->scope)->toBe(['company' => 'company-uuid'])
        ->and($controller->companyQuery->calls)->toBe([
            ['where', 'status', 'active'],
            ['whereHas', 'driver', [['where', 'public_id', 'driver_public']]],
            ['whereHas', 'vehicle', [['where', 'public_id', 'vehicle_public']]],
            ['whereDate', 'scheduled_date', '2026-05-01'],
            ['orderByDesc', 'created_at'],
            ['paginate', 15],
        ]);
});

test('manifest controller shows cancels destroys and updates stops through status transitions', function () {
    $controller = new FleetOpsManifestControllerProbe();

    $show    = $controller->show('manifest_public')->getData(true);
    $cancel  = $controller->cancel('manifest_public')->getData(true);
    $destroy = $controller->destroy('manifest_public')->getData(true);

    $arrived   = $controller->updateStop(new Request(['status' => 'arrived']), 'stop_public')->getData(true);
    $completed = $controller->updateStop(new Request(['status' => 'completed']), 'stop_public')->getData(true);
    $skipped   = $controller->updateStop(new Request(['status' => 'skipped']), 'stop_public')->getData(true);
    $patched   = $controller->updateStop(new Request(['status' => 'rescheduled', 'sequence' => 4, 'ignored' => true]), 'stop_public')->getData(true);

    expect($show['manifest'])->toMatchArray(['public_id' => 'manifest_public'])
        ->and($controller->manifestQuery->calls)->toContain(['firstOrFail'])
        ->and($cancel)->toMatchArray(['status' => 'cancelled'])
        ->and($destroy)->toBe(['deleted' => true])
        ->and($arrived['stop']['status'])->toBe('arrived')
        ->and($completed['stop']['status'])->toBe('completed')
        ->and($skipped['stop']['status'])->toBe('skipped')
        ->and($patched['stop'])->toMatchArray(['status' => 'rescheduled', 'sequence' => 4])
        ->and($controller->stopQuery->record->updates)->toBe([['status' => 'rescheduled', 'sequence' => 4]])
        ->and($controller->stopQuery->record->freshes[0])->toBe(['place', 'order.trackingNumber']);
});
