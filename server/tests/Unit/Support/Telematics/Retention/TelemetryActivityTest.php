<?php

use Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy;
use Fleetbase\FleetOps\Support\Telematics\Retention\TelemetryActivity;
use Spatie\Activitylog\ActivityLogger;

/**
 * Telemetry writes bypass the activity log unless the company opted in, and
 * still run when no activity logger is available.
 */
function fleetopsTelemetryActivityLogger(): ActivityLogger
{
    $logger = new class extends ActivityLogger {
        public int $suppressed = 0;

        public function __construct()
        {
        }

        public function withoutLogs(Closure $callback): mixed
        {
            $this->suppressed++;

            return $callback();
        }
    };
    app()->instance(ActivityLogger::class, $logger);

    return $logger;
}

beforeEach(function () {
    RetentionPolicy::flush();
    config(['telematics.telemetry' => []]);
});

afterEach(function () {
    RetentionPolicy::flush();
    RetentionPolicy::$settingsResolver = null;
    app()->forgetInstance(ActivityLogger::class);
});

test('telemetry activity is suppressed by default and logged when a company opts in', function () {
    RetentionPolicy::$settingsResolver = fn (string $scope, string $key, mixed $default, ?string $company) => $company === 'verbose' ? ['log_telemetry_activity' => true] : [];
    $logger                            = fleetopsTelemetryActivityLogger();

    expect(TelemetryActivity::run('quiet', fn () => 'saved'))->toBe('saved')
        ->and($logger->suppressed)->toBe(1)
        ->and(TelemetryActivity::run('verbose', fn () => 'logged'))->toBe('logged')
        ->and($logger->suppressed)->toBe(1)
        ->and(TelemetryActivity::run(null, fn () => 'system'))->toBe('system')
        ->and($logger->suppressed)->toBe(2);
});

test('telemetry writes still run when the activity logger cannot be resolved', function () {
    RetentionPolicy::$settingsResolver = fn () => [];
    app()->forgetInstance(ActivityLogger::class);

    expect(TelemetryActivity::run('company-1', fn () => 'unlogged'))->toBe('unlogged');
});
