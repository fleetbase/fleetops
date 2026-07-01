<?php

namespace Fleetbase\FleetOps\Support\Ai;

use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;

class FleetOpsAiQueryResources
{
    public static function register(AiQueryRegistry $registry): void
    {
        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.drivers',
            label: 'Fleet-Ops drivers',
            module: 'fleet-ops',
            modelClass: Driver::class,
            permission: 'fleet-ops see driver',
            aliases: ['drivers', 'driver'],
            fields: [
                'online'         => ['column' => 'online', 'type' => 'boolean'],
                'status'         => ['column' => 'status', 'type' => 'string'],
                'current_status' => ['column' => 'current_status', 'type' => 'string'],
                'city'           => ['column' => 'city', 'type' => 'string'],
                'country'        => ['column' => 'country', 'type' => 'string'],
                'vehicle_uuid'   => ['column' => 'vehicle_uuid', 'type' => 'uuid'],
                'updated_at'     => ['column' => 'updated_at', 'type' => 'datetime'],
                'created_at'     => ['column' => 'created_at', 'type' => 'datetime'],
            ],
            sampleFields: ['public_id', 'name', 'status', 'current_status', 'online', 'city', 'country', 'vehicle_uuid'],
            locationField: 'location',
            directivePermission: 'fleet-ops list driver',
            maxLimit: 500,
        ));

        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.vehicles',
            label: 'Fleet-Ops vehicles',
            module: 'fleet-ops',
            modelClass: Vehicle::class,
            permission: 'fleet-ops see vehicle',
            aliases: ['vehicles', 'vehicle'],
            fields: [
                'online'      => ['column' => 'online', 'type' => 'boolean'],
                'status'      => ['column' => 'status', 'type' => 'string'],
                'driver_uuid' => ['column' => 'driver_uuid', 'type' => 'uuid'],
                'city'        => ['column' => 'city', 'type' => 'string'],
                'country'     => ['column' => 'country', 'type' => 'string'],
                'updated_at'  => ['column' => 'updated_at', 'type' => 'datetime'],
                'created_at'  => ['column' => 'created_at', 'type' => 'datetime'],
            ],
            sampleFields: ['public_id', 'display_name', 'status', 'online', 'city', 'country', 'driver_uuid'],
            locationField: 'location',
            directivePermission: 'fleet-ops list vehicle',
            maxLimit: 500,
        ));

        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.devices',
            label: 'Fleet-Ops devices',
            module: 'fleet-ops',
            modelClass: Device::class,
            permission: 'fleet-ops see device',
            aliases: ['devices', 'device'],
            fields: [
                'online'          => ['column' => 'online', 'type' => 'boolean'],
                'status'          => ['column' => 'status', 'type' => 'string'],
                'provider'        => ['column' => 'provider', 'type' => 'string'],
                'telematic_uuid'  => ['column' => 'telematic_uuid', 'type' => 'uuid'],
                'attachable_uuid' => ['column' => 'attachable_uuid', 'type' => 'uuid'],
                'last_online_at'  => ['column' => 'last_online_at', 'type' => 'datetime'],
                'updated_at'      => ['column' => 'updated_at', 'type' => 'datetime'],
            ],
            sampleFields: ['public_id', 'name', 'status', 'online', 'provider', 'telematic_uuid', 'attachable_uuid'],
            directivePermission: 'fleet-ops list device',
            maxLimit: 500,
        ));

        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.orders',
            label: 'Fleet-Ops orders',
            module: 'fleet-ops',
            modelClass: Order::class,
            permission: 'fleet-ops see order',
            aliases: ['orders', 'order'],
            fields: [
                'status'                => ['column' => 'status', 'type' => 'string'],
                'type'                  => ['column' => 'type', 'type' => 'string'],
                'driver_assigned_uuid'  => ['column' => 'driver_assigned_uuid', 'type' => 'uuid'],
                'vehicle_assigned_uuid' => ['column' => 'vehicle_assigned_uuid', 'type' => 'uuid'],
                'created_at'            => ['column' => 'created_at', 'type' => 'datetime'],
                'scheduled_at'          => ['column' => 'scheduled_at', 'type' => 'datetime'],
            ],
            sampleFields: ['public_id', 'status', 'type', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'created_at'],
            directivePermission: 'fleet-ops list order',
            maxLimit: 500,
        ));

        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.fleets',
            label: 'Fleet-Ops fleets',
            module: 'fleet-ops',
            modelClass: Fleet::class,
            permission: 'fleet-ops see fleet',
            aliases: ['fleets', 'fleet'],
            fields: [
                'status'     => ['column' => 'status', 'type' => 'string'],
                'task'       => ['column' => 'task', 'type' => 'string'],
                'updated_at' => ['column' => 'updated_at', 'type' => 'datetime'],
            ],
            sampleFields: ['public_id', 'name', 'status', 'task'],
            directivePermission: 'fleet-ops list fleet',
        ));

        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.service_areas',
            label: 'Fleet-Ops service areas',
            module: 'fleet-ops',
            modelClass: ServiceArea::class,
            permission: 'fleet-ops see service-area',
            aliases: ['service areas', 'service area', 'service_areas'],
            fields: [
                'status'  => ['column' => 'status', 'type' => 'string'],
                'country' => ['column' => 'country', 'type' => 'string'],
                'type'    => ['column' => 'type', 'type' => 'string'],
            ],
            sampleFields: ['public_id', 'name', 'status', 'country', 'type'],
        ));

        $registry->register(new AiQueryableResource(
            key: 'fleet-ops.zones',
            label: 'Fleet-Ops zones',
            module: 'fleet-ops',
            modelClass: Zone::class,
            permission: 'fleet-ops see zone',
            aliases: ['zones', 'zone'],
            fields: [
                'status'            => ['column' => 'status', 'type' => 'string'],
                'service_area_uuid' => ['column' => 'service_area_uuid', 'type' => 'uuid'],
            ],
            sampleFields: ['public_id', 'name', 'status', 'service_area_uuid'],
        ));
    }
}
