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

test('company expansion exposes driver relationship contract', function () {
    $company  = Mockery::mock();
    $relation = Mockery::mock();

    $company->shouldReceive('hasMany')
        ->once()
        ->with(Driver::class)
        ->andReturn($relation);

    $drivers = bindFleetOpsExpansionClosure(CompanyExpansion::drivers(), $company);

    expect(CompanyExpansion::target())->toBe(\Fleetbase\Models\Company::class)
        ->and($drivers())->toBe($relation);
});

test('user expansion exposes driver and customer relationship contracts', function () {
    $user = Mockery::mock();

    $driverRelation = Mockery::mock();
    $driverRelation->shouldReceive('without')->once()->with('user')->andReturn('driver-relation');

    $driverProfilesRelation = Mockery::mock();

    $customerRelation = Mockery::mock();
    $customerRelation->shouldReceive('where')->once()->with('type', 'customer')->andReturnSelf();
    $customerRelation->shouldReceive('without')->once()->with('user')->andReturn('customer-relation');

    $contactRelation = Mockery::mock();
    $contactRelation->shouldReceive('without')->once()->with('user')->andReturn('contact-relation');

    $user->shouldReceive('hasOne')->once()->with(Driver::class)->andReturn($driverRelation);
    $user->shouldReceive('hasMany')->once()->with(Driver::class)->andReturn($driverProfilesRelation);
    $user->shouldReceive('hasOne')->once()->with(Contact::class, 'user_uuid', 'uuid')->andReturn($customerRelation);
    $user->shouldReceive('hasOne')->once()->with(Contact::class, 'user_uuid', 'uuid')->andReturn($contactRelation);

    $driver         = bindFleetOpsExpansionClosure(UserExpansion::driver(), $user);
    $driverProfiles = bindFleetOpsExpansionClosure(UserExpansion::driverProfiles(), $user);
    $customer       = bindFleetOpsExpansionClosure(UserExpansion::customer(), $user);
    $contact        = bindFleetOpsExpansionClosure(UserExpansion::contact(), $user);

    expect(UserExpansion::target())->toBe(\Fleetbase\Models\User::class)
        ->and($driver())->toBe('driver-relation')
        ->and($driverProfiles())->toBe($driverProfilesRelation)
        ->and($customer())->toBe('customer-relation')
        ->and($contact())->toBe('contact-relation');
});

test('user expansion filters current driver session to the active company', function () {
    session(['company' => 'company-uuid']);

    $relation = Mockery::mock();
    $relation->shouldReceive('where')->once()->with('company_uuid', 'company-uuid')->andReturn('current-session');

    $user = new class($relation) {
        public function __construct(public $relation)
        {
        }

        public function driver()
        {
            return $this->relation;
        }
    };

    $currentDriverSession = bindFleetOpsExpansionClosure(UserExpansion::currentDriverSession(), $user);

    expect($currentDriverSession())->toBe('current-session');
});

test('user filter expansion applies simple user type filters', function () {
    $builder = Mockery::mock();
    $filter  = new class($builder) {
        public function __construct(public $builder)
        {
        }
    };

    $builder->shouldReceive('where')->once()->with('type', 'customer')->andReturnSelf();
    bindFleetOpsExpansionClosure(UserFilterExpansion::isCustomer(), $filter)();

    $builder->shouldReceive('whereIn')->once()->with('type', ['user', 'admin'])->andReturnSelf();
    bindFleetOpsExpansionClosure(UserFilterExpansion::canBeDriver(), $filter)();

    expect(UserFilterExpansion::target())->toBe(\Fleetbase\Http\Filter\UserFilter::class);
});

test('user filter expansion applies driver and customer relationship filters', function () {
    session(['company' => 'company-uuid']);

    $builder = Mockery::mock();
    $filter  = new class($builder) {
        public function __construct(public $builder)
        {
        }
    };

    $builder->shouldReceive('where')
        ->once()
        ->with(Mockery::type(Closure::class))
        ->andReturnUsing(function (Closure $callback) use ($builder) {
            $query = Mockery::mock();
            $query->shouldReceive('where')->once()->with('type', 'driver')->andReturnSelf();
            $query->shouldReceive('orwhereHas')
                ->once()
                ->with('driverProfiles', Mockery::type(Closure::class))
                ->andReturnUsing(function ($relation, Closure $nested) {
                    $nestedQuery = Mockery::mock();
                    $nestedQuery->shouldReceive('where')->once()->with('company_uuid', 'company-uuid')->andReturnSelf();
                    $nested($nestedQuery);

                    return $nestedQuery;
                });

            $callback($query);

            return $builder;
        });

    bindFleetOpsExpansionClosure(UserFilterExpansion::isDriver(), $filter)();

    $builder->shouldReceive('where')
        ->once()
        ->with(Mockery::type(Closure::class))
        ->andReturnUsing(function (Closure $callback) use ($builder) {
            $query = Mockery::mock();
            $query->shouldReceive('whereNull')->once()->with('type')->andReturnSelf();
            $query->shouldReceive('orWhere')->once()->with('type', '!=', 'customer')->andReturnSelf();

            $callback($query);

            return $builder;
        });

    bindFleetOpsExpansionClosure(UserFilterExpansion::isNotCustomer(), $filter)();

    foreach ([
        [UserFilterExpansion::doesntHaveDriver(), 'driverProfiles'],
        [UserFilterExpansion::doesntHaveContact(), 'contact'],
        [UserFilterExpansion::doesntHaveCustomer(), 'customer'],
    ] as [$closure, $relationship]) {
        $builder->shouldReceive('whereDoesntHave')
            ->once()
            ->with($relationship, Mockery::type(Closure::class))
            ->andReturnUsing(function ($relation, Closure $nested) use ($builder) {
                $query = Mockery::mock();
                $query->shouldReceive('where')->once()->with('company_uuid', 'company-uuid')->andReturnSelf();
                $nested($query);

                return $builder;
            });

        bindFleetOpsExpansionClosure($closure, $filter)();
    }
});
