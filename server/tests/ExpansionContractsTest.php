<?php

use Fleetbase\FleetOps\Expansions\CompanyExpansion;
use Fleetbase\FleetOps\Expansions\UserExpansion;
use Fleetbase\FleetOps\Expansions\UserFilterExpansion;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;

function bindFleetOpsExpansionClosure(Closure $closure, object $target): Closure
{
    return $closure->bindTo($target, $target::class);
}

class FleetOpsExpansionRelationFake
{
    public array $calls = [];

    public function __construct(public string $name)
    {
    }

    public function without($relation)
    {
        $this->calls[] = ['without', $relation];

        return $this;
    }

    public function where($column, $operator = null, $value = null)
    {
        $this->calls[] = ['where', $column, $operator, $value];

        return $this;
    }
}

class FleetOpsExpansionModelFake
{
    public array $calls     = [];
    public array $relations = [];

    public function __construct()
    {
        foreach (['driver', 'driverProfiles', 'customer', 'contact'] as $name) {
            $this->relations[$name] = new FleetOpsExpansionRelationFake($name);
        }
    }

    public function hasMany($class)
    {
        $this->calls[] = ['hasMany', $class];

        return $this->relations['driverProfiles'];
    }

    public function hasOne($class, $foreignKey = null, $localKey = null)
    {
        $this->calls[] = ['hasOne', $class, $foreignKey, $localKey];

        if ($class === Driver::class) {
            return $this->relations['driver'];
        }

        return count(array_filter($this->calls, fn ($call) => $call[0] === 'hasOne' && $call[1] === Contact::class)) === 1
            ? $this->relations['customer']
            : $this->relations['contact'];
    }

    public function driver()
    {
        return $this->relations['driver'];
    }
}

class FleetOpsFilterQueryFake
{
    public array $calls = [];

    public function where($column, $operator = null, $value = null)
    {
        $this->calls[] = ['where', $column, $operator, $value];

        return $this;
    }

    public function whereNull($column)
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null)
    {
        $this->calls[] = ['orWhere', $column, $operator, $value];

        return $this;
    }

    public function orwhereHas($relation, Closure $callback)
    {
        $nested = new self();
        $callback($nested);

        $this->calls[] = ['orwhereHas', $relation, $nested->calls];

        return $this;
    }
}

class FleetOpsFilterBuilderFake
{
    public array $calls = [];

    public function where($column, $operator = null, $value = null)
    {
        if ($column instanceof Closure) {
            $query = new FleetOpsFilterQueryFake();
            $column($query);
            $this->calls[] = ['whereNested', $query->calls];

            return $this;
        }

        $this->calls[] = ['where', $column, $operator, $value];

        return $this;
    }

    public function whereIn($column, array $values)
    {
        $this->calls[] = ['whereIn', $column, $values];

        return $this;
    }

    public function whereDoesntHave($relation, Closure $callback)
    {
        $query = new FleetOpsFilterQueryFake();
        $callback($query);
        $this->calls[] = ['whereDoesntHave', $relation, $query->calls];

        return $this;
    }
}

test('company expansion exposes driver relationship contract', function () {
    $company = new FleetOpsExpansionModelFake();
    $drivers = bindFleetOpsExpansionClosure(CompanyExpansion::drivers(), $company);

    expect(CompanyExpansion::target())->toBe(Fleetbase\Models\Company::class)
        ->and($drivers())->toBe($company->relations['driverProfiles'])
        ->and($company->calls)->toBe([['hasMany', Driver::class]]);
});

test('user expansion exposes driver and customer relationship contracts', function () {
    $user = new FleetOpsExpansionModelFake();

    $driver         = bindFleetOpsExpansionClosure(UserExpansion::driver(), $user);
    $driverProfiles = bindFleetOpsExpansionClosure(UserExpansion::driverProfiles(), $user);
    $customer       = bindFleetOpsExpansionClosure(UserExpansion::customer(), $user);
    $contact        = bindFleetOpsExpansionClosure(UserExpansion::contact(), $user);

    expect(UserExpansion::target())->toBe(Fleetbase\Models\User::class)
        ->and($driver())->toBe($user->relations['driver'])
        ->and($driverProfiles())->toBe($user->relations['driverProfiles'])
        ->and($customer())->toBe($user->relations['customer'])
        ->and($contact())->toBe($user->relations['contact'])
        ->and($user->relations['driver']->calls)->toBe([['without', 'user']])
        ->and($user->relations['customer']->calls)->toBe([
            ['where', 'type', 'customer', null],
            ['without', 'user'],
        ])
        ->and($user->relations['contact']->calls)->toBe([['without', 'user']]);
});

test('user expansion filters current driver session to the active company', function () {
    session(['company' => 'company-uuid']);

    $user                 = new FleetOpsExpansionModelFake();
    $currentDriverSession = bindFleetOpsExpansionClosure(UserExpansion::currentDriverSession(), $user);

    expect($currentDriverSession())->toBe($user->relations['driver'])
        ->and($user->relations['driver']->calls)->toBe([['where', 'company_uuid', 'company-uuid', null]]);
});

test('user filter expansion applies simple user type filters', function () {
    $builder = new FleetOpsFilterBuilderFake();
    $filter  = new class($builder) {
        public function __construct(public $builder)
        {
        }
    };

    bindFleetOpsExpansionClosure(UserFilterExpansion::isCustomer(), $filter)();
    bindFleetOpsExpansionClosure(UserFilterExpansion::canBeDriver(), $filter)();

    expect(UserFilterExpansion::target())->toBe(Fleetbase\Http\Filter\UserFilter::class)
        ->and($builder->calls)->toBe([
            ['where', 'type', 'customer', null],
            ['whereIn', 'type', ['user', 'admin']],
        ]);
});

test('user filter expansion applies driver and customer relationship filters', function () {
    session(['company' => 'company-uuid']);

    $builder = new FleetOpsFilterBuilderFake();
    $filter  = new class($builder) {
        public function __construct(public $builder)
        {
        }
    };

    bindFleetOpsExpansionClosure(UserFilterExpansion::isDriver(), $filter)();
    bindFleetOpsExpansionClosure(UserFilterExpansion::isNotCustomer(), $filter)();
    bindFleetOpsExpansionClosure(UserFilterExpansion::doesntHaveDriver(), $filter)();
    bindFleetOpsExpansionClosure(UserFilterExpansion::doesntHaveContact(), $filter)();
    bindFleetOpsExpansionClosure(UserFilterExpansion::doesntHaveCustomer(), $filter)();

    expect($builder->calls)->toBe([
        ['whereNested', [
            ['where', 'type', 'driver', null],
            ['orwhereHas', 'driverProfiles', [
                ['where', 'company_uuid', 'company-uuid', null],
            ]],
        ]],
        ['whereNested', [
            ['whereNull', 'type'],
            ['orWhere', 'type', '!=', 'customer'],
        ]],
        ['whereDoesntHave', 'driverProfiles', [
            ['where', 'company_uuid', 'company-uuid', null],
        ]],
        ['whereDoesntHave', 'contact', [
            ['where', 'company_uuid', 'company-uuid', null],
        ]],
        ['whereDoesntHave', 'customer', [
            ['where', 'company_uuid', 'company-uuid', null],
        ]],
    ]);
});
