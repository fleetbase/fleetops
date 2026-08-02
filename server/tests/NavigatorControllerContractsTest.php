<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return 'http://localhost/' . ltrim($path, '/');
    }
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\NavigatorController;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

class FleetOpsNavigatorControllerProbe extends NavigatorController
{
    public ?User $adminUser              = null;
    public ?ApiCredential $apiCredential = null;
    public ?Company $organization        = null;
    public mixed $settings               = null;
    public array $redirects              = [];
    public array $credentialLookups      = [];
    public array $organizationLookups    = [];

    protected function findAdminUser(): ?User
    {
        return $this->adminUser;
    }

    protected function firstOrCreateNavigatorCredential(User $adminUser): ApiCredential
    {
        $this->credentialLookups[] = ['navigator', $adminUser->uuid, $adminUser->company_uuid];

        return $this->apiCredential;
    }

    protected function secureRootUrl(): string
    {
        return 'https://api.fleetbase.test';
    }

    protected function navigatorAppIdentifier(): string
    {
        return 'io.fleetbase.navigator.test';
    }

    protected function socketClusterHost(): string
    {
        return 'socket.test';
    }

    protected function socketClusterPort(): int|string
    {
        return 7001;
    }

    protected function socketClusterSecure(): bool
    {
        return true;
    }

    protected function redirectAway(string $url)
    {
        $this->redirects[] = $url;

        return response()->json(['redirect' => $url]);
    }

    protected function findApiCredentialForToken(?string $token, string $connection, bool $isSecretKey): ?ApiCredential
    {
        $this->credentialLookups[] = ['token', $token, $connection, $isSecretKey];

        return $this->apiCredential;
    }

    protected function findOrganization(string $companyUuid): ?Company
    {
        $this->organizationLookups[] = $companyUuid;

        return $this->organization;
    }

    protected function driverOnboardSettings(): mixed
    {
        return $this->settings;
    }
}

function fleetopsNavigatorUser(?Company $company = null): User
{
    $user = new User();
    $user->setRawAttributes([
        'uuid'         => 'user-uuid',
        'company_uuid' => $company?->uuid,
    ], true);
    $user->setRelation('company', $company);

    return $user;
}

function fleetopsNavigatorCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes([
        'uuid'      => 'company-uuid',
        'public_id' => 'company_public',
        'name'      => 'Acme Logistics',
    ], true);

    return $company;
}

function fleetopsNavigatorCredential(string $key = 'flb_live_key'): ApiCredential
{
    $credential = new ApiCredential();
    $credential->setRawAttributes([
        'uuid'         => 'credential-uuid',
        'key'          => $key,
        'secret'       => '$secret',
        'company_uuid' => 'company-uuid',
    ], true);

    return $credential;
}

test('navigator controller builds android and ios app link redirects', function () {
    $company                   = fleetopsNavigatorCompany();
    $controller                = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser     = fleetopsNavigatorUser($company);
    $controller->apiCredential = fleetopsNavigatorCredential('flb_live_navigator');

    $android = $controller->linkApp(Request::create('/navigator/link-app', 'GET', [], [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Android',
    ]))->getData(true);

    $ios = $controller->linkApp(Request::create('/navigator/link-app', 'GET', [], [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 iPhone',
    ]))->getData(true);

    expect($android['redirect'])->toStartWith('intent://configure?')
        ->and($android['redirect'])->toContain('key=flb_live_navigator')
        ->and($android['redirect'])->toContain('host=https%3A%2F%2Fapi.fleetbase.test')
        ->and($android['redirect'])->toContain('package=io.fleetbase.navigator.test;end')
        ->and($ios['redirect'])->toStartWith('flbnavigator://configure?')
        ->and($ios['redirect'])->toContain('socketcluster_host=socket.test')
        ->and($ios['redirect'])->toContain('socketcluster_port=7001')
        ->and($ios['redirect'])->toContain('socketcluster_secure=1')
        ->and($controller->credentialLookups)->toBe([
            ['navigator', 'user-uuid', 'company-uuid'],
            ['navigator', 'user-uuid', 'company-uuid'],
        ]);
});

test('navigator controller returns missing organization error when no admin company is available', function () {
    $controller            = new FleetOpsNavigatorControllerProbe();
    $controller->adminUser = fleetopsNavigatorUser(null);

    $response = $controller->linkApp(new Request());

    expect($response->getData(true))->toBe(['error' => 'Organization for linking not found.']);
});

test('navigator controller exposes link url settings and current organization token lookup branches', function () {
    $controller                = new FleetOpsNavigatorControllerProbe();
    $controller->apiCredential = fleetopsNavigatorCredential('flb_test_key');
    $controller->organization  = fleetopsNavigatorCompany();
    $controller->settings      = ['enabled' => true, 'invite_code_required' => false];

    $linkUrl  = $controller->getLinkAppUrl()->getData(true);
    $settings = $controller->getDriverOnboardSettings()->getData(true);

    $testKeyRequest = Request::create('/navigator/current-organization', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer flb_test_key',
    ]);
    $organization = $controller->getCurrentOrganization($testKeyRequest);

    $controller->apiCredential = null;
    $secretRequest             = Request::create('/navigator/current-organization', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer $secret',
    ]);
    $missing = $controller->getCurrentOrganization($secretRequest);

    expect($linkUrl)->toBe(['linkUrl' => 'http://localhost/int/v1/fleet-ops/navigator/link-app'])
        ->and($settings)->toBe(['enabled' => true, 'invite_code_required' => false])
        ->and($organization)->toBeInstanceOf(Organization::class)
        ->and($controller->credentialLookups)->toContain(
            ['token', 'flb_test_key', 'sandbox', false],
            ['token', '$secret', 'mysql', true]
        )
        ->and($controller->organizationLookups)->toBe(['company-uuid'])
        ->and($missing->getData(true))->toBe(['error' => 'No API key found to fetch company details with.']);
});
