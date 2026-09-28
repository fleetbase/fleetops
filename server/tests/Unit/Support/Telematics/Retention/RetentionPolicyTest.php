<?php

use Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy;
use Illuminate\Support\Carbon;

/**
 * RetentionPolicy layers package config, the admin setting and the company
 * setting, clamps every value, and caches per company for long-lived workers.
 */
function fleetopsRetentionResolver(array $system, array $company, ?array &$calls = null): void
{
    $calls                             = [];
    RetentionPolicy::$settingsResolver = function (string $scope, string $key, mixed $default, ?string $companyUuid) use ($system, $company, &$calls) {
        $calls[] = [$scope, $key, $companyUuid];
        expect($key)->toBe(RetentionPolicy::SETTING_KEY);

        return $scope === 'system' ? $system : ($company[$companyUuid] ?? $default);
    };
}

beforeEach(function () {
    RetentionPolicy::flush();
    RetentionPolicy::$settingsResolver = null;
    config(['telematics.telemetry' => ['event_retention_days' => 45, 'processed_retention_hours' => 48, 'log_telemetry_activity' => true, 'poll_queue' => 'default']]);
});

afterEach(function () {
    RetentionPolicy::flush();
    RetentionPolicy::$settingsResolver = null;
    Carbon::setTestNow();
    config(['telematics.telemetry' => []]);
});

test('defaults come from package config, overridden by the admin setting, with everything clamped', function () {
    fleetopsRetentionResolver([], []);
    expect(RetentionPolicy::defaults())->toBe([
        'event_retention_days'      => 45,
        'event_compact_after_days'  => 7,
        'position_retention_days'   => 90,
        'processed_retention_hours' => 48,
        'quarantine_retention_days' => 7,
        'sync_run_retention_days'   => 7,
        'log_telemetry_activity'    => true,
    ]);

    RetentionPolicy::flush();
    fleetopsRetentionResolver([
        'event_retention_days'      => 99999,
        'event_compact_after_days'  => '',
        'position_retention_days'   => 0,
        'quarantine_retention_days' => -5,
        'processed_retention_hours' => null,
        'log_telemetry_activity'    => 'no',
        'ignored'                   => 'value',
    ], []);
    $defaults = RetentionPolicy::defaults();
    expect($defaults['event_retention_days'])->toBe(3650)
        ->and($defaults['event_compact_after_days'])->toBe(7)
        ->and($defaults['position_retention_days'])->toBe(0)
        ->and($defaults['quarantine_retention_days'])->toBe(1)
        ->and($defaults['processed_retention_hours'])->toBe(48)
        ->and($defaults['log_telemetry_activity'])->toBeFalse()
        ->and($defaults)->not->toHaveKey('ignored')
        ->and(RetentionPolicy::keys())->toBe(array_keys(RetentionPolicy::FALLBACKS));
});

test('company policies layer over the defaults and are cached until flushed', function () {
    fleetopsRetentionResolver(['position_retention_days' => 30], ['company-1' => ['event_retention_days' => 10, 'log_telemetry_activity' => false]], $calls);

    $policy = RetentionPolicy::forCompany('company-1');
    expect($policy->get('event_retention_days'))->toBe(10)
        ->and($policy->get('position_retention_days'))->toBe(30)
        ->and($policy->get('processed_retention_hours'))->toBe(48)
        ->and($policy->logsTelemetryActivity())->toBeFalse()
        ->and($policy->toArray())->toHaveKeys(RetentionPolicy::keys())
        ->and(RetentionPolicy::forCompany('company-1'))->toBe($policy)
        ->and(RetentionPolicy::forCompany(null)->toArray())->toBe(RetentionPolicy::defaults())
        ->and(RetentionPolicy::forCompany('company-2')->get('event_retention_days'))->toBe(45)
        ->and(array_column($calls, 0))->toBe(['system', 'company', 'company']);

    RetentionPolicy::flush('company-1');
    RetentionPolicy::forCompany('company-1');
    RetentionPolicy::forCompany('company-2');
    expect(count($calls))->toBe(4);

    RetentionPolicy::flush();
    RetentionPolicy::forCompany('company-2');
    expect(array_column($calls, 0))->toBe(['system', 'company', 'company', 'company', 'system', 'company']);
});

test('cutoffs, compaction and deletion rules follow the clamped values', function () {
    Carbon::setTestNow('2026-09-23 12:00:00 UTC');

    $policy = RetentionPolicy::fromArray(['event_retention_days' => 10, 'event_compact_after_days' => 10, 'processed_retention_hours' => 6, 'position_retention_days' => 0]);
    expect($policy->deletesEvents())->toBeTrue()
        ->and($policy->compactsEvents())->toBeFalse()
        ->and($policy->cutoff('processed_retention_hours')?->toDateTimeString())->toBe('2026-09-23 06:00:00')
        ->and($policy->cutoff('event_retention_days')?->toDateTimeString())->toBe('2026-09-13 12:00:00')
        ->and($policy->cutoff('position_retention_days'))->toBeNull()
        ->and($policy->cutoff('unknown'))->toBeNull()
        ->and($policy->get('unknown'))->toBeNull();

    expect(RetentionPolicy::fromArray(['event_retention_days' => 10, 'event_compact_after_days' => 3])->compactsEvents())->toBeTrue()
        ->and(RetentionPolicy::fromArray(['event_retention_days' => 0, 'event_compact_after_days' => 5])->compactsEvents())->toBeTrue()
        ->and(RetentionPolicy::fromArray(['event_retention_days' => 0, 'event_compact_after_days' => 5])->deletesEvents())->toBeFalse()
        ->and(RetentionPolicy::fromArray(['event_compact_after_days' => 0])->compactsEvents())->toBeFalse()
        ->and(RetentionPolicy::fromArray([], ['event_retention_days' => 12])->get('event_retention_days'))->toBe(12)
        ->and(RetentionPolicy::normalize(['log_telemetry_activity' => '1'], [])['log_telemetry_activity'])->toBeTrue();
});

test('a failing or malformed settings store falls back to the layer below', function () {
    RetentionPolicy::$settingsResolver = fn () => throw new RuntimeException('settings unavailable');
    expect(RetentionPolicy::forCompany('company-1')->get('event_retention_days'))->toBe(45);

    RetentionPolicy::flush();
    RetentionPolicy::$settingsResolver = fn () => 'not-an-array';
    expect(RetentionPolicy::defaults()['processed_retention_hours'])->toBe(48);
});

test('company lookups scope the session for the setting store and restore it afterwards', function () {
    // Without a resolver the policy reaches Setting, which has no database here and throws.
    app('session')->put('company', 'previous-company');
    expect(RetentionPolicy::forCompany('company-9')->get('event_retention_days'))->toBe(45)
        ->and(app('session')->get('company'))->toBe('previous-company');

    app('session')->forget('company');
    RetentionPolicy::flush();
    expect(RetentionPolicy::forCompany('company-9')->get('processed_retention_hours'))->toBe(48)
        ->and(app('session')->has('company'))->toBeFalse();
});
