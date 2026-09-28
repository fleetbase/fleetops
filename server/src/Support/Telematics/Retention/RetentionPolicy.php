<?php

namespace Fleetbase\FleetOps\Support\Telematics\Retention;

use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Resolves how long a company keeps telematics data.
 *
 * Values resolve in three layers: the package config (`telematics.telemetry`),
 * the system-wide admin setting, then the company setting. Every numeric value
 * is clamped to its limits; zero always means "keep forever".
 */
final class RetentionPolicy
{
    public const SETTING_KEY = 'fleet-ops.telematics-settings';

    /** Cached policies are refreshed after this many seconds so saved settings reach long-lived workers. */
    public const CACHE_TTL_SECONDS = 60;

    /** @var array<string, array{0: int, 1: int}> */
    public const LIMITS = [
        'event_retention_days'      => [1, 3650],
        'event_compact_after_days'  => [1, 3650],
        'position_retention_days'   => [1, 3650],
        'processed_retention_hours' => [1, 720],
        'quarantine_retention_days' => [1, 365],
        'sync_run_retention_days'   => [1, 365],
    ];

    public const BOOLEANS = ['log_telemetry_activity'];

    /** Used when neither config nor settings provide a value. */
    public const FALLBACKS = [
        'event_retention_days'      => 30,
        'event_compact_after_days'  => 7,
        'position_retention_days'   => 90,
        'processed_retention_hours' => 24,
        'quarantine_retention_days' => 7,
        'sync_run_retention_days'   => 7,
        'log_telemetry_activity'    => false,
    ];

    /**
     * Test seam: fn (string $scope, string $key, mixed $default, ?string $companyUuid): mixed
     * where $scope is `system` or `company`.
     */
    public static ?\Closure $settingsResolver = null;

    /** @var array<string, array{policy: self, expires: int}> */
    private static array $companyCache = [];

    /** @var array{values: array, expires: int}|null */
    private static ?array $defaultsCache = null;

    private function __construct(private array $values)
    {
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::FALLBACKS);
    }

    /**
     * System-wide defaults: package config overridden by the admin setting.
     */
    public static function defaults(): array
    {
        if (self::$defaultsCache && self::$defaultsCache['expires'] > time()) {
            return self::$defaultsCache['values'];
        }

        $config = array_intersect_key((array) config('telematics.telemetry', []), self::FALLBACKS);
        $base   = self::normalize($config, self::FALLBACKS);
        $values = self::normalize(self::resolve('system', null), $base);

        self::$defaultsCache = ['values' => $values, 'expires' => time() + self::CACHE_TTL_SECONDS];

        return $values;
    }

    public static function forCompany(?string $companyUuid): self
    {
        if (!$companyUuid) {
            return new self(self::defaults());
        }

        $cached = self::$companyCache[$companyUuid] ?? null;
        if ($cached && $cached['expires'] > time()) {
            return $cached['policy'];
        }

        $defaults = self::defaults();
        $policy   = new self(self::normalize(self::resolve('company', $companyUuid), $defaults));

        self::$companyCache[$companyUuid] = ['policy' => $policy, 'expires' => time() + self::CACHE_TTL_SECONDS];

        return $policy;
    }

    public static function fromArray(array $values, ?array $base = null): self
    {
        return new self(self::normalize($values, $base ?? self::FALLBACKS));
    }

    /**
     * Clamp and cast user input, filling gaps from `$base`.
     */
    public static function normalize(array $input, array $base): array
    {
        $values = [];
        foreach (self::LIMITS as $key => [$min, $max]) {
            $value        = (int) self::pick($input, $base, $key);
            $values[$key] = $value === 0 ? 0 : max($min, min($max, $value));
        }
        foreach (self::BOOLEANS as $key) {
            $values[$key] = filter_var(self::pick($input, $base, $key), FILTER_VALIDATE_BOOLEAN);
        }

        return $values;
    }

    public static function flush(?string $companyUuid = null): void
    {
        if ($companyUuid === null) {
            self::$companyCache  = [];
            self::$defaultsCache = null;

            return;
        }

        unset(self::$companyCache[$companyUuid]);
    }

    /**
     * The point in time before which rows governed by `$key` are expired, or null when retention is off.
     */
    public function cutoff(string $key): ?Carbon
    {
        $value = (int) ($this->values[$key] ?? 0);
        if ($value <= 0) {
            return null;
        }

        return $key === 'processed_retention_hours' ? Carbon::now()->subHours($value) : Carbon::now()->subDays($value);
    }

    public function deletesEvents(): bool
    {
        return $this->values['event_retention_days'] > 0;
    }

    /**
     * Compaction strips raw payloads from events that are kept but no longer need the provider blob.
     * It is pointless when events are deleted before, or at the same age as, they would be compacted.
     */
    public function compactsEvents(): bool
    {
        $compactAfter = $this->values['event_compact_after_days'];
        if ($compactAfter <= 0) {
            return false;
        }

        return !$this->deletesEvents() || $compactAfter < $this->values['event_retention_days'];
    }

    public function logsTelemetryActivity(): bool
    {
        return (bool) $this->values['log_telemetry_activity'];
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    private static function pick(array $input, array $base, string $key): mixed
    {
        if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
            return $input[$key];
        }

        return $base[$key] ?? self::FALLBACKS[$key];
    }

    /**
     * Read the stored setting for a scope. Any failure (no settings table, no session store)
     * falls back to the layer below so ingestion never depends on the settings store.
     */
    private static function resolve(string $scope, ?string $companyUuid): array
    {
        try {
            if (self::$settingsResolver) {
                $value = (self::$settingsResolver)($scope, self::SETTING_KEY, [], $companyUuid);
            } elseif ($scope === 'system') {
                $value = Setting::lookup(self::SETTING_KEY, []);
            } else {
                $value = self::lookupCompanySetting($companyUuid);
            }
        } catch (\Throwable) {
            $value = [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * `Setting::lookupCompany` reads the company from the session, which queue workers and
     * console commands do not have; scope it for the lookup and restore whatever was there.
     */
    private static function lookupCompanySetting(string $companyUuid): mixed
    {
        $session  = app('session');
        $had      = $session->has('company');
        $previous = $session->get('company');
        $session->put('company', $companyUuid);

        try {
            return Setting::lookupCompany(self::SETTING_KEY, []);
        } finally {
            $had ? $session->put('company', $previous) : $session->forget('company');
        }
    }
}
