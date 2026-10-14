<?php

namespace Fleetbase\FleetOps\Orchestration\Support;

use Fleetbase\Models\Setting;

/**
 * OrchestratorSettings.
 *
 * Single reader for the organization's Orchestrator settings. The settings
 * page saves them as one company setting (`fleet-ops.allocation-settings`);
 * every runtime consumer (workbench runs, the public API, auto-allocation)
 * reads them through here so they all see what the organization selected.
 */
class OrchestratorSettings
{
    public const KEY = 'fleet-ops.allocation-settings';

    /**
     * System-level engine key read before the company setting existed.
     * Still honoured as the default for organizations that never saved.
     */
    public const LEGACY_ENGINE_KEY = 'fleetops.orchestrator_engine';

    public const DEFAULT_ENGINE = 'greedy';

    public static function defaults(): array
    {
        return [
            'allocation_engine'           => static::DEFAULT_ENGINE,
            'auto_allocate_on_create'     => false,
            'auto_reallocate_on_complete' => false,
            'max_travel_time_seconds'     => 3600,
            'balance_workload'            => false,
        ];
    }

    /**
     * Resolve the organization's settings merged over the defaults.
     */
    public static function forCompany(?string $companyUuid): array
    {
        $saved    = Setting::lookupForCompany($companyUuid, static::KEY, []);
        $saved    = array_filter(is_array($saved) ? $saved : [], fn ($value) => $value !== null && $value !== '');
        $defaults = static::defaults();

        if (!isset($saved['allocation_engine'])) {
            $defaults['allocation_engine'] = Setting::lookup(static::LEGACY_ENGINE_KEY) ?: static::DEFAULT_ENGINE;
        }

        return array_merge($defaults, $saved);
    }

    /**
     * Identifier of the orchestration engine the organization selected.
     */
    public static function engineForCompany(?string $companyUuid): string
    {
        return (string) static::forCompany($companyUuid)['allocation_engine'];
    }
}
