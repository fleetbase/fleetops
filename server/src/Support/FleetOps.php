<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Company;
use Illuminate\Support\Str;

class FleetOps
{
    /**
     * Creates or retrieves an existing transport configuration for a given company.
     *
     * This method attempts to find a transport configuration (`OrderConfig`) for the specified company.
     * If such a configuration exists, it's returned. Otherwise, a new configuration is created with default values,
     * such as the company UUID, key, core service flag, status, version, tags, and predefined workflow steps.
     * These steps include 'created', 'enroute', 'started', 'completed', and 'dispatched', each with specific attributes.
     *
     * @param Company $company the company for which the transport configuration is being created or retrieved
     *
     * @return OrderConfig the transport configuration associated with the specified company
     */
    public static function createTransportConfig(Company $company): OrderConfig
    {
        return OrderConfig::firstOrCreate(
            [
                'company_uuid' => $company->uuid,
                'key'          => 'transport',
                'namespace'    => 'system:order-config:transport',
            ],
            static::transportConfigDefaults()
        );
    }

    private static function transportConfigDefaults(): array
    {
        return [
            'name'         => 'Transport',
            'key'          => 'transport',
            'namespace'    => 'system:order-config:transport',
            'description'  => 'Default order configuration for transport',
            'core_service' => 1,
            'status'       => 'private',
            'version'      => '0.0.1',
            'tags'         => ['transport', 'delivery'],
            'entities'     => [],
            'meta'         => [],
            'flow'         => [
                'created' => [
                    'key'         => 'created',
                    'code'        => 'created',
                    'color'       => '#1f2937',
                    'logic'       => [],
                    'events'      => [],
                    'status'      => 'Order Created',
                    'actions'     => [],
                    'details'     => 'New order was created.',
                    'options'     => [],
                    'complete'    => false,
                    'entities'    => [],
                    'sequence'    => 0,
                    'activities'  => ['dispatched'],
                    'internalId'  => Str::uuid(),
                    'pod_method'  => 'scan',
                    'require_pod' => false,
                ],
                'enroute' => [
                    'key'         => 'enroute',
                    'code'        => 'enroute',
                    'color'       => '#1f2937',
                    'logic'       => [],
                    'events'      => [],
                    'status'      => 'Driver Enroute',
                    'actions'     => [],
                    'details'     => 'Driver is en-route.',
                    'options'     => [],
                    'complete'    => false,
                    'entities'    => [],
                    'sequence'    => 0,
                    'activities'  => ['completed'],
                    'internalId'  => Str::uuid(),
                    'pod_method'  => 'scan',
                    'require_pod' => false,
                ],
                'started' => [
                    'key'         => 'started',
                    'code'        => 'started',
                    'color'       => '#1f2937',
                    'logic'       => [],
                    'events'      => [],
                    'status'      => 'Order Started',
                    'actions'     => [],
                    'details'     => 'Order has been started',
                    'options'     => [],
                    'complete'    => false,
                    'entities'    => [],
                    'sequence'    => 0,
                    'activities'  => ['enroute'],
                    'internalId'  => Str::uuid(),
                    'pod_method'  => 'scan',
                    'require_pod' => false,
                ],
                'completed' => [
                    'key'         => 'completed',
                    'code'        => 'completed',
                    'color'       => '#1f2937',
                    'logic'       => [],
                    'events'      => [],
                    'status'      => 'Order Completed',
                    'actions'     => [],
                    'details'     => 'Order has been completed.',
                    'options'     => [],
                    'complete'    => true,
                    'entities'    => [],
                    'sequence'    => 0,
                    'activities'  => [],
                    'internalId'  => Str::uuid(),
                    'pod_method'  => 'scan',
                    'require_pod' => false,
                ],
                'dispatched' => [
                    'key'         => 'dispatched',
                    'code'        => 'dispatched',
                    'color'       => '#1f2937',
                    'logic'       => [],
                    'events'      => [],
                    'status'      => 'Order Dispatched',
                    'actions'     => [],
                    'details'     => 'Order has been dispatched.',
                    'options'     => [],
                    'complete'    => false,
                    'entities'    => [],
                    'sequence'    => 0,
                    'activities'  => ['started'],
                    'internalId'  => Str::uuid(),
                    'pod_method'  => 'scan',
                    'require_pod' => false,
                ],
            ],
        ];
    }

    /**
     * Creates or retrieves the ride config for a company: a rider's trip from request to drop-off.
     *
     * The driver accepting starts the order ("on the way"), then arrives, starts the trip and
     * completes it, or records a no-show. Transitions are strict, and the order is offered to
     * one driver at a time.
     */
    public static function createRideConfig(Company $company): OrderConfig
    {
        return OrderConfig::firstOrCreate(
            [
                'company_uuid' => $company->uuid,
                'key'          => 'ride',
                'namespace'    => 'system:order-config:ride',
            ],
            static::rideConfigDefaults()
        );
    }

    /**
     * The ride flow, keyed by activity code.
     */
    public static function rideConfigDefaults(): array
    {
        $activity = fn (string $code, string $status, string $details, array $next, bool $complete = false) => [
            'key'         => $code,
            'code'        => $code,
            'color'       => '#1f2937',
            'logic'       => [],
            'events'      => [],
            'status'      => $status,
            'actions'     => [],
            'details'     => $details,
            'options'     => [],
            'complete'    => $complete,
            'entities'    => [],
            'sequence'    => 0,
            'activities'  => $next,
            'internalId'  => (string) Str::uuid(),
            'pod_method'  => 'scan',
            'require_pod' => false,
        ];

        return [
            'name'         => 'Ride',
            'key'          => 'ride',
            'namespace'    => 'system:order-config:ride',
            'description'  => 'A rider\'s trip, from request to drop-off',
            'core_service' => 1,
            'status'       => 'private',
            'version'      => '0.0.1',
            'tags'         => ['ride', 'passenger'],
            'entities'     => [],
            'meta'         => [
                'lifecycle' => [
                    'initial'            => 'created',
                    'completed'          => 'completed',
                    'canceled'           => 'canceled',
                    'terminal'           => ['no_show'],
                    'strict_transitions' => true,
                ],
                'offers' => ['enabled' => true, 'timeout' => 20],
            ],
            'flow' => [
                'created'     => $activity('created', 'Ride requested', 'The rider requested a ride.', ['dispatched']),
                'dispatched'  => $activity('dispatched', 'Finding a driver', 'Looking for a driver nearby.', ['started']),
                'started'     => $activity('started', 'Driver on the way', 'The driver accepted and is on the way to the pickup.', ['arrived']),
                'arrived'     => $activity('arrived', 'Driver has arrived', 'The driver is waiting at the pickup.', ['in_progress', 'no_show']),
                'in_progress' => $activity('in_progress', 'Trip in progress', 'The rider is on board.', ['completed']),
                'completed'   => $activity('completed', 'Trip completed', 'The rider was dropped off.', [], true),
                'no_show'     => $activity('no_show', 'Rider didn\'t show', 'The rider didn\'t come to the pickup.', []),
            ],
        ];
    }
}
