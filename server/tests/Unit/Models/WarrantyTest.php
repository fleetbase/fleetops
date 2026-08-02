<?php

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return new class($logName) {
        public array $properties = [];
        public array $logs = [];
        public function __construct(public ?string $logName = null) {}
        public function performedOn($subject) { return $this; }
        public function withProperties(array $properties) { $this->properties = $properties; return $this; }
        public function log(string $message) { $this->logs[] = $message; return $this; }
    }; }');
}

use Fleetbase\FleetOps\Models\Asset;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;

class FleetOpsWarrantyQueryFake
{
    public array $calls = [];

    public function where($column, $operator = null, $value = null): self
    {
        $this->calls[] = ['where', $column, $operator, $value];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null): self
    {
        $this->calls[] = ['orWhere', $column, $operator, $value];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereBetween(string $column, array $range): self
    {
        $this->calls[] = ['whereBetween', $column, $range];

        return $this;
    }
}

class FleetOpsWarrantyRootQueryFake extends FleetOpsWarrantyQueryFake
{
    public function where($column, $operator = null, $value = null): self
    {
        if ($column instanceof Closure) {
            $nested = new FleetOpsWarrantyQueryFake();
            $column($nested);
            $this->calls[] = ['whereNested', $nested->calls];

            return $this;
        }

        return parent::where($column, $operator, $value);
    }
}

class FleetOpsSuccessfulWarrantyFake extends Warranty
{
    public array $updates = [];

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

function fleetopsWarrantyUnitUseInMemoryConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

beforeEach(function () {
    fleetopsWarrantyUnitUseInMemoryConnection();
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('warranty relationship contracts activity options and null accessors are stable', function () {
    $warranty = new Warranty();

    expect($warranty->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($warranty->vendor())->toBeInstanceOf(BelongsTo::class)
        ->and($warranty->vendor()->getRelated())->toBeInstanceOf(Vendor::class)
        ->and($warranty->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($warranty->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($warranty->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($warranty->updatedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($warranty->subject())->toBeInstanceOf(MorphTo::class)
        ->and($warranty->subject_name)->toBeNull()
        ->and($warranty->vendor_name)->toBeNull()
        ->and((new Warranty())->status)->toBe('pending');
});

test('warranty scopes write active expired and expiring soon constraints', function () {
    $warranty = new Warranty();

    $active = new FleetOpsWarrantyRootQueryFake();
    expect($warranty->scopeActive($active))->toBe($active)
        ->and($active->calls)->toHaveCount(2)
        ->and($active->calls[0][0])->toBe('whereNested')
        ->and($active->calls[0][1][0])->toBe(['whereNull', 'start_date'])
        ->and($active->calls[0][1][1][0])->toBe('orWhere')
        ->and($active->calls[0][1][1][1])->toBe('start_date')
        ->and($active->calls[0][1][1][2])->toBe('<=')
        ->and($active->calls[1][1][0])->toBe(['whereNull', 'end_date'])
        ->and($active->calls[1][1][1][0])->toBe('orWhere')
        ->and($active->calls[1][1][1][1])->toBe('end_date')
        ->and($active->calls[1][1][1][2])->toBe('>=');

    $expired = new FleetOpsWarrantyRootQueryFake();
    expect($warranty->scopeExpired($expired))->toBe($expired)
        ->and($expired->calls[0])->toBe(['whereNotNull', 'end_date'])
        ->and($expired->calls[1][0])->toBe('where')
        ->and($expired->calls[1][1])->toBe('end_date')
        ->and($expired->calls[1][2])->toBe('<');

    $expiringSoon = new FleetOpsWarrantyRootQueryFake();
    expect($warranty->scopeExpiringSoon($expiringSoon, 45))->toBe($expiringSoon)
        ->and($expiringSoon->calls[0])->toBe(['whereNotNull', 'end_date'])
        ->and($expiringSoon->calls[1][0])->toBe('whereBetween')
        ->and($expiringSoon->calls[1][1])->toBe('end_date')
        ->and($expiringSoon->calls[1][2][0]->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($expiringSoon->calls[1][2][1]->toDateTimeString())->toBe('2026-09-10 12:00:00');
});

test('warranty successful transfer updates subject and logs transfer properties', function () {
    $warranty = new FleetOpsSuccessfulWarrantyFake([
        'subject_type' => Asset::class,
        'subject_uuid' => 'old-asset-uuid',
        'terms'        => ['transferable' => true],
    ]);

    $newSubject = new Asset();
    $newSubject->forceFill(['uuid' => 'new-asset-uuid']);

    expect($warranty->transferTo($newSubject, ['reason' => 'sold']))->toBeTrue()
        ->and($warranty->updates)->toHaveCount(1)
        ->and($warranty->updates[0])->toBe([
            'subject_type' => Asset::class,
            'subject_uuid' => 'new-asset-uuid',
        ])
        ->and($warranty->subject_type)->toBe(Asset::class)
        ->and($warranty->subject_uuid)->toBe('new-asset-uuid');
});
