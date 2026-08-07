<?php

use Fleetbase\FleetOps\Http\Middleware\SetupDriverSession;

class FleetOpsSetupDriverSessionStore
{
    public static array $values = [];

    public function put(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }
}

if (!function_exists('Fleetbase\FleetOps\Http\Middleware\session')) {
    eval('namespace Fleetbase\FleetOps\Http\Middleware; function session($key = null, $default = null) { if ($key === null) { return new \FleetOpsSetupDriverSessionStore(); } return \FleetOpsSetupDriverSessionStore::$values[$key] ?? $default; }');
}

test('setup driver session stores driver uuid in the active session', function () {
    FleetOpsSetupDriverSessionStore::$values = [];

    $middleware = new class extends SetupDriverSession {
        public function storeForTest(string $driverUuid): void
        {
            $this->storeDriverInSession($driverUuid);
        }
    };

    $middleware->storeForTest('driver-session-uuid');

    expect(FleetOpsSetupDriverSessionStore::$values['driver'])->toBe('driver-session-uuid');
});
