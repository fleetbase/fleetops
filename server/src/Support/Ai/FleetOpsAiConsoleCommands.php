<?php

namespace Fleetbase\FleetOps\Support\Ai;

/**
 * Fleet-Ops pages and dialogs Fleetbase AI may offer to open. Definitions are plain arrays so Fleet-Ops
 * does not depend on the AI extension's classes; nothing runs until the user confirms it in the console.
 */
class FleetOpsAiConsoleCommands
{
    public const ENGINE = '@fleetbase/fleetops-engine';

    protected const DOCS = 'https://fleetbase.io/docs/fleet-ops';

    public static function all(): array
    {
        return array_merge(static::resources(), static::settings());
    }

    /**
     * Open, create, import, and view commands for Fleet-Ops records.
     */
    public static function resources(): array
    {
        $resources = [
            // key => [singular, plural, list route, new route, detail route, permission resource, actions service, supports import, docs path, keywords]
            'orders'       => ['order', 'Orders', 'operations.orders.index', 'operations.orders.index.new', 'operations.orders.index.details', 'order', 'order-actions', 'importOrders', 'operations/orders', ['dispatch', 'delivery', 'shipment', 'job']],
            'drivers'      => ['driver', 'Drivers', 'management.drivers.index', 'management.drivers.index.new', 'management.drivers.index.details', 'driver', 'driver-actions', 'import', 'resources/drivers', ['courier', 'navigator']],
            'vehicles'     => ['vehicle', 'Vehicles', 'management.vehicles.index', 'management.vehicles.index.new', 'management.vehicles.index.details', 'vehicle', 'vehicle-actions', 'import', 'resources/vehicles', ['truck', 'van', 'car', 'fleet']],
            'contacts'     => ['contact', 'Contacts', 'management.contacts.index', 'management.contacts.index.new', 'management.contacts.index.details', 'contact', 'contact-actions', 'import', 'resources/contacts', ['person']],
            'customers'    => ['customer', 'Customers', 'management.contacts.customers', 'management.contacts.customers.new', 'management.contacts.customers.details', 'contact', 'customer-actions', 'import', 'resources/contacts', ['client', 'contacts']],
            'places'       => ['place', 'Places', 'management.places.index', 'management.places.index.new', 'management.places.index.details', 'place', 'place-actions', 'import', 'resources/places', ['address', 'location', 'depot', 'warehouse']],
            'fleets'       => ['fleet', 'Fleets', 'management.fleets.index', 'management.fleets.index.new', 'management.fleets.index.details', 'fleet', 'fleet-actions', null, 'resources/fleets', ['team']],
            'issues'       => ['issue', 'Issues', 'management.issues.index', 'management.issues.index.new', 'management.issues.index.details', 'issue', 'issue-actions', null, 'resources/issues', ['problem', 'incident']],
            'work_orders'  => ['work order', 'Work Orders', 'maintenance.work-orders.index', 'maintenance.work-orders.index.new', 'maintenance.work-orders.index.details', 'work-order', 'work-order-actions', null, 'maintenance/work-orders', ['maintenance', 'repair']],
            'maintenances' => ['maintenance', 'Maintenances', 'maintenance.maintenances.index', 'maintenance.maintenances.index.new', 'maintenance.maintenances.index.details', 'maintenance', 'maintenance-actions', null, 'maintenance', ['service', 'repair']],
        ];

        $commands = [];

        foreach ($resources as $key => [$singular, $plural, $listRoute, $newRoute, $detailRoute, $permission, $service, $import, $docs, $keywords]) {
            $base = [
                'breadcrumb' => "Fleet-Ops › {$plural}",
                'keywords'   => array_merge(['fleet-ops', $singular], $keywords),
                'docs_url'   => static::DOCS . "/{$docs}",
                'module'     => 'fleet-ops',
            ];

            $commands[] = $base + [
                'id'          => "fleet-ops.{$key}.open",
                'label'       => "Open {$plural}",
                'description' => "Go to the Fleet-Ops {$plural} list.",
                'steps'       => [['type' => 'navigate', 'route' => "console.fleet-ops.{$listRoute}"]],
                'permissions' => ["fleet-ops list {$permission}"],
            ];

            $commands[] = $base + [
                'id'          => "fleet-ops.{$key}.create",
                'label'       => "Create {$singular}",
                'description' => "Open the new {$singular} form in Fleet-Ops.",
                'steps'       => [['type' => 'navigate', 'route' => "console.fleet-ops.{$newRoute}"]],
                'permissions' => ["fleet-ops create {$permission}"],
                'keywords'    => array_merge($base['keywords'], ['new', 'add', 'create']),
            ];

            $commands[] = $base + [
                'id'          => "fleet-ops.{$key}.view",
                'label'       => "Open {$singular}",
                'description' => "Open a specific {$singular} by its public id.",
                'steps'       => [['type' => 'navigate', 'route' => "console.fleet-ops.{$detailRoute}", 'models' => ['public_id']]],
                'permissions' => ["fleet-ops see {$permission}"],
                'params'      => ['public_id' => ['type' => 'string', 'description' => "The {$singular} public id."]],
            ];

            if ($import) {
                $commands[] = $base + [
                    'id'          => "fleet-ops.{$key}.import",
                    'label'       => "Import {$plural}",
                    'description' => "Go to {$plural} in Fleet-Ops and open the spreadsheet import, including the template download.",
                    'steps'       => [
                        ['type' => 'navigate', 'route' => "console.fleet-ops.{$listRoute}"],
                        ['type' => 'service', 'engine' => static::ENGINE, 'service' => $service, 'method' => $import],
                    ],
                    'permissions' => ["fleet-ops import {$permission}"],
                    'keywords'    => array_merge($base['keywords'], ['import', 'spreadsheet', 'csv', 'excel', 'xlsx', 'template', 'bulk', 'upload']),
                ];
            }
        }

        return $commands;
    }

    public static function settings(): array
    {
        $settings = [
            'map'           => ['Map', 'map-settings', ['map', 'provider', 'google maps', 'leaflet', 'openstreetmap', 'tiles', 'view maps']],
            'navigator-app' => ['Navigator App', 'navigator-settings', ['navigator', 'driver app', 'mobile app', 'onboard']],
            'routing'       => ['Routing', 'routing-settings', ['routing', 'route', 'optimization', 'osrm', 'distance']],
            'notifications' => ['Notifications', 'notification-settings', ['notification', 'alert', 'email', 'sms']],
            'custom-fields' => ['Custom Fields', 'custom-field', ['custom field', 'field', 'attribute']],
            'scheduling'    => ['Scheduling', null, ['schedule', 'shift', 'calendar']],
        ];

        $commands = [];

        foreach ($settings as $route => [$label, $permission, $keywords]) {
            $commands[] = [
                'id'          => 'fleet-ops.settings.' . str_replace('-', '_', $route) . '.open',
                'label'       => "Open {$label} settings",
                'breadcrumb'  => "Fleet-Ops › Settings › {$label}",
                'description' => "Go to the Fleet-Ops {$label} settings.",
                'steps'       => [['type' => 'navigate', 'route' => "console.fleet-ops.settings.{$route}"]],
                'permissions' => $permission ? ["fleet-ops view {$permission}"] : [],
                'keywords'    => array_merge(['fleet-ops', 'settings', 'configure'], $keywords),
                'docs_url'    => static::DOCS . "/settings/{$route}",
                'module'      => 'fleet-ops',
            ];
        }

        return $commands;
    }
}
