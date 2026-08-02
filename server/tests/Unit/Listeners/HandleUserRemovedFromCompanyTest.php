<?php

if (!class_exists('Illuminate\Foundation\Auth\User', false)) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\FleetOps\Listeners\HandleUserRemovedFromCompany;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;

class FleetOpsUserRemovedCompanyDriverQueryFake
{
    public bool $deleted = false;

    public function delete(): void
    {
        $this->deleted = true;
    }
}

class FleetOpsUserRemovedCompanyListenerProbe extends HandleUserRemovedFromCompany
{
    public array $criteria = [];
    public FleetOpsUserRemovedCompanyDriverQueryFake $query;

    public function __construct()
    {
        $this->query = new FleetOpsUserRemovedCompanyDriverQueryFake();
    }

    protected function driverQuery(array $criteria): mixed
    {
        $this->criteria[] = $criteria;

        return $this->query;
    }
}

test('user removed company listener deletes drivers matching company and user criteria', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-uuid'], true);

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-uuid'], true);

    $listener = new FleetOpsUserRemovedCompanyListenerProbe();

    $listener->handle(new UserRemovedFromCompany($user, $company));

    expect($listener->criteria)->toBe([
        [
            'company_uuid' => 'company-uuid',
            'user_uuid'    => 'user-uuid',
        ],
    ])->and($listener->query->deleted)->toBeTrue();
});
