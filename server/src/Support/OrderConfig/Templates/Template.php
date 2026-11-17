<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Templates;

use Fleetbase\FleetOps\Contracts\OrderConfigTemplate;
use Illuminate\Support\Str;

/**
 * A template described by its properties, with a helper for writing flow activities.
 */
abstract class Template implements OrderConfigTemplate
{
    protected string $key;
    protected string $name;
    protected string $description;

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function customFields(): array
    {
        return [];
    }

    /**
     * A flow activity in the stored format.
     *
     * @param array $next    the codes of the activities that can follow
     * @param array $actions triggers to run when an order reaches the activity
     * @param array $extra   any other activity attributes, such as `require_pod`
     */
    protected static function activity(string $code, string $status, string $details, array $next = [], bool $complete = false, array $actions = [], array $extra = []): array
    {
        return array_replace([
            'key'         => $code,
            'code'        => $code,
            'color'       => '#1f2937',
            'logic'       => [],
            'events'      => [],
            'status'      => $status,
            'actions'     => $actions,
            'details'     => $details,
            'options'     => [],
            'complete'    => $complete,
            'entities'    => [],
            'sequence'    => 0,
            'activities'  => $next,
            'internalId'  => (string) Str::uuid(),
            'pod_method'  => 'scan',
            'require_pod' => false,
        ], $extra);
    }
}
