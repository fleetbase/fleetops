<?php

use Fleetbase\FleetOps\Console\Commands\PruneTelematicsData;
use Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy;
use Illuminate\Support\Collection;

/**
 * Drives fleetops:prune-telematics-data without the console kernel: options,
 * output and the settings-backed policy are all supplied by the test.
 */
class PruneTelematicsDataProbe extends PruneTelematicsData
{
    public array $messages = [];
    public array $options  = ['company' => null, 'table' => [], 'batch-size' => 1000, 'max-batches' => 50, 'dry-run' => false, 'no-lock' => true];

    /** company uuid ('' for the system defaults) => policy values passed to RetentionPolicy::fromArray */
    public array $policies = [];

    /** When set, replaces the companies table lookup. */
    public ?Collection $companyRows = null;

    public function option($key = null, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function policyFor(?string $companyUuid): RetentionPolicy
    {
        return RetentionPolicy::fromArray($this->policies[$companyUuid ?? ''] ?? $this->policies[''] ?? []);
    }

    protected function companies(?string $only): Collection
    {
        if ($this->companyRows === null) {
            return parent::companies($only);
        }

        return $this->companyRows->filter(fn ($company) => !$only || $company->uuid === $only || $company->public_id === $only)->values();
    }
}
