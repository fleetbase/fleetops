<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Fleetbase\Seeders\Concerns\ResolvesSeedCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Explicit test-data seeder. Kept below server/seeders/Testing so fleetbase:seed
 * does not auto-discover it during deploys.
 *
 * Run with:
 * php artisan db:seed --class="Fleetbase\\FleetOps\\Seeders\\Testing\\VroomOrchestrationSeeder"
 */
class VroomOrchestrationSeeder extends Seeder
{
    use ResolvesSeedCompany;

    protected const SEED_NAME = 'vroom-orchestration';

    public function run(): void
    {
        $company = $this->resolveCompany();
        if (!$company) {
            $this->command?->error('No company found. Create a Fleetbase company before running the VROOM orchestration seeder.');

            return;
        }

        session(['company' => $company->uuid]);

        DB::transaction(function () use ($company) {
            $this->purgePreviousSeedData();

            $places   = $this->seedPlaces($company);
            $vehicles = $this->seedVehicles($company);
            $orders   = $this->seedOrders($company, $places);

            $this->command?->info('Seeded VROOM orchestration test data for company: ' . $company->public_id);
            $this->command?->line('Order IDs: ' . implode(', ', array_map(fn (Order $order) => $order->public_id, $orders)));
            $this->command?->line('Vehicle IDs: ' . implode(', ', array_map(fn (Vehicle $vehicle) => $vehicle->public_id, $vehicles)));
        });
    }

    protected function resolveCompany(): ?Company
    {
        return $this->resolveSeedCompany(
            'FLEETOPS_VROOM_SEED_COMPANY_UUID',
            'FLEETOPS_VROOM_SEED_COMPANY_PUBLIC_ID'
        );
    }

    protected function purgePreviousSeedData(): void
    {
        $payloadUuids = $this->seeded(Payload::query())->pluck('uuid');

        $this->seeded(Order::query())->forceDelete();
        Entity::whereIn('payload_uuid', $payloadUuids)->forceDelete();
        Waypoint::whereIn('payload_uuid', $payloadUuids)->forceDelete();
        $this->seeded(Payload::query())->forceDelete();
        $this->seeded(Vehicle::query())->forceDelete();
        $this->seeded(Place::query())->forceDelete();
    }

    protected function seeded($query)
    {
        return $query->where('meta->seed', static::SEED_NAME);
    }

    protected function meta(string $seedId, array $extra = []): array
    {
        return array_merge([
            'seed'    => static::SEED_NAME,
            'seed_id' => $seedId,
        ], $extra);
    }

    protected function seedPlaces(Company $company): array
    {
        $places = [
            'depot' => [
                'seed_id'   => 'depot_tanjong_pagar',
                'name'      => 'VROOM Test Depot - Tanjong Pagar',
                'street1'   => '1 Raffles Quay',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.2816,
                'lng'       => 103.8510,
            ],
            'orchard' => [
                'seed_id'   => 'dropoff_orchard',
                'name'      => 'VROOM Test Dropoff - Orchard',
                'street1'   => '2 Orchard Turn',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3048,
                'lng'       => 103.8318,
            ],
            'changi' => [
                'seed_id'   => 'dropoff_changi',
                'name'      => 'VROOM Test Dropoff - Changi',
                'street1'   => '80 Airport Boulevard',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3644,
                'lng'       => 103.9915,
            ],
            'woodlands' => [
                'seed_id'   => 'dropoff_woodlands',
                'name'      => 'VROOM Test Dropoff - Woodlands',
                'street1'   => '1 Woodlands Square',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.4360,
                'lng'       => 103.7860,
            ],
            'rochor' => [
                'seed_id'   => 'waypoint_rochor',
                'name'      => 'VROOM Test Waypoint - Rochor',
                'street1'   => '1 Rochor Canal Road',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3039,
                'lng'       => 103.8520,
            ],
            'novena' => [
                'seed_id'   => 'waypoint_novena',
                'name'      => 'VROOM Test Waypoint - Novena',
                'street1'   => '10 Sinaran Drive',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3206,
                'lng'       => 103.8439,
            ],
            'serangoon' => [
                'seed_id'   => 'waypoint_serangoon',
                'name'      => 'VROOM Test Waypoint - Serangoon',
                'street1'   => '23 Serangoon Central',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3508,
                'lng'       => 103.8723,
            ],
            'paya_lebar' => [
                'seed_id'   => 'waypoint_paya_lebar',
                'name'      => 'VROOM Test Waypoint - Paya Lebar',
                'street1'   => '10 Paya Lebar Road',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3174,
                'lng'       => 103.8927,
            ],
            'tampines' => [
                'seed_id'   => 'waypoint_tampines',
                'name'      => 'VROOM Test Waypoint - Tampines',
                'street1'   => '4 Tampines Central 5',
                'city'      => 'Singapore',
                'country'   => 'SG',
                'lat'       => 1.3525,
                'lng'       => 103.9447,
            ],
        ];

        return collect($places)->mapWithKeys(function (array $place, string $key) use ($company) {
            $model = new Place();
            $model->forceFill([
                'uuid'         => (string) Str::uuid(),
                'company_uuid' => $company->uuid,
                'name'         => $place['name'],
                'street1'      => $place['street1'],
                'city'         => $place['city'],
                'country'      => $place['country'],
                'location'     => new Point($place['lat'], $place['lng']),
                'meta'         => $this->meta($place['seed_id']),
            ])->save();

            return [$key => $model];
        })->all();
    }

    protected function seedVehicles(Company $company): array
    {
        $vehicles = [
            [
                'seed_id'   => 'central_1',
                'name'      => 'VROOM Test Van - Central',
                'make'      => 'Nissan',
                'model'     => 'NV200',
                'lat'       => 1.2798,
                'lng'       => 103.8500,
                'capacity'  => [120, 4.5, 1, 20],
            ],
            [
                'seed_id'   => 'east_1',
                'name'      => 'VROOM Test Van - East',
                'make'      => 'Toyota',
                'model'     => 'HiAce',
                'lat'       => 1.3349,
                'lng'       => 103.9617,
                'capacity'  => [250, 8, 2, 40],
            ],
            [
                'seed_id'   => 'north_1',
                'name'      => 'VROOM Test Van - North',
                'make'      => 'Mercedes-Benz',
                'model'     => 'Sprinter',
                'lat'       => 1.4326,
                'lng'       => 103.7856,
                'capacity'  => [500, 16, 4, 80],
            ],
            [
                'seed_id'   => 'west_1',
                'name'      => 'VROOM Test Van - West',
                'make'      => 'Ford',
                'model'     => 'Transit',
                'lat'       => 1.3345,
                'lng'       => 103.7420,
                'capacity'  => [90, 3, 0, 12],
            ],
            [
                'seed_id'   => 'capacity_only_heavy_1',
                'name'      => 'VROOM Test Capacity Truck - No Position',
                'make'      => 'Isuzu',
                'model'     => 'NPR',
                'capacity'  => [900, 24, 8, 160],
                'skills'    => ['tail_lift'],
                'max_tasks' => 10,
            ],
            [
                'seed_id'   => 'capacity_only_cold_1',
                'name'      => 'VROOM Test Cold Chain Van - No Position',
                'make'      => 'Hyundai',
                'model'     => 'H350',
                'capacity'  => [350, 10, 3, 60],
                'skills'    => ['cold_chain'],
                'max_tasks' => 8,
            ],
        ];

        return collect($vehicles)->mapWithKeys(function (array $vehicle) use ($company) {
            $model = new Vehicle();
            $model->forceFill([
                'uuid'                     => (string) Str::uuid(),
                'company_uuid'             => $company->uuid,
                'name'                     => $vehicle['name'],
                'make'                     => $vehicle['make'],
                'model'                    => $vehicle['model'],
                'year'                     => '2024',
                'type'                     => 'van',
                'status'                   => 'available',
                'online'                   => true,
                'location'                 => isset($vehicle['lat'], $vehicle['lng']) ? new Point($vehicle['lat'], $vehicle['lng']) : null,
                'skills'                   => $vehicle['skills'] ?? [],
                'payload_capacity'         => $vehicle['capacity'][0],
                'payload_capacity_volume'  => $vehicle['capacity'][1],
                'payload_capacity_pallets' => $vehicle['capacity'][2],
                'payload_capacity_parcels' => $vehicle['capacity'][3],
                'max_tasks'                => $vehicle['max_tasks'] ?? 6,
                'return_to_depot'          => false,
                'meta'                     => $this->meta($vehicle['seed_id'], [
                    'location_state' => isset($vehicle['lat'], $vehicle['lng']) ? 'set' : 'not_set',
                    'capacity'       => [
                        'weight_kg'     => $vehicle['capacity'][0],
                        'volume_m3'     => $vehicle['capacity'][1],
                        'pallets'       => $vehicle['capacity'][2],
                        'parcels'       => $vehicle['capacity'][3],
                    ],
                ]),
            ])->save();

            return [$vehicle['seed_id'] => $model];
        })->all();
    }

    protected function seedOrders(Company $company, array $places): array
    {
        $orders = [
            'endpoint_only' => [
                'payload_seed_id' => 'endpoint_only',
                'name'            => 'VROOM Test Order - Pickup Dropoff',
                'pickup'          => $places['depot'],
                'dropoff'         => $places['orchard'],
                'waypoints'       => [],
                'entities'        => [
                    ['name' => 'Small carton', 'type' => 'parcel', 'weight' => 12, 'weight_unit' => 'kg', 'length' => 60, 'width' => 40, 'height' => 35, 'dimensions_unit' => 'cm'],
                    ['name' => 'Fragile tote', 'type' => 'parcel', 'weight' => 8, 'weight_unit' => 'kg', 'length' => 50, 'width' => 35, 'height' => 30, 'dimensions_unit' => 'cm'],
                ],
            ],
            'waypoint_only' => [
                'payload_seed_id' => 'waypoint_only',
                'name'            => 'VROOM Test Order - Waypoints Only',
                'pickup'          => null,
                'dropoff'         => null,
                'waypoints'       => [$places['rochor'], $places['novena'], $places['serangoon']],
                'entities'        => [
                    ['name' => 'Multi-stop parcel 1', 'type' => 'parcel', 'weight' => 25, 'weight_unit' => 'kg', 'length' => 80, 'width' => 50, 'height' => 40, 'dimensions_unit' => 'cm'],
                    ['name' => 'Multi-stop parcel 2', 'type' => 'parcel', 'weight' => 18, 'weight_unit' => 'kg', 'length' => 70, 'width' => 45, 'height' => 35, 'dimensions_unit' => 'cm'],
                    ['name' => 'Multi-stop parcel 3', 'type' => 'parcel', 'weight' => 15, 'weight_unit' => 'kg', 'length' => 65, 'width' => 40, 'height' => 35, 'dimensions_unit' => 'cm'],
                ],
            ],
            'mixed' => [
                'payload_seed_id' => 'mixed',
                'name'            => 'VROOM Test Order - Mixed Stops',
                'pickup'          => $places['depot'],
                'dropoff'         => $places['changi'],
                'waypoints'       => [$places['paya_lebar'], $places['tampines']],
                'entities'        => [
                    ['name' => 'Heavy equipment crate', 'type' => 'freight', 'weight' => 180, 'weight_unit' => 'kg', 'length' => 120, 'width' => 80, 'height' => 90, 'dimensions_unit' => 'cm'],
                    ['name' => 'Accessory box', 'type' => 'parcel', 'weight' => 22, 'weight_unit' => 'kg', 'length' => 75, 'width' => 55, 'height' => 45, 'dimensions_unit' => 'cm'],
                ],
            ],
            'single_waypoint' => [
                'payload_seed_id' => 'single_waypoint',
                'name'            => 'VROOM Test Order - Single Waypoint',
                'pickup'          => null,
                'dropoff'         => null,
                'waypoints'       => [$places['woodlands']],
                'entities'        => [
                    ['name' => 'Oversized sample case', 'type' => 'parcel', 'weight' => 60, 'weight_unit' => 'kg', 'length' => 100, 'width' => 60, 'height' => 60, 'dimensions_unit' => 'cm'],
                ],
            ],
        ];

        return collect($orders)->mapWithKeys(function (array $definition, string $seedId) use ($company) {
            $payload = $this->createPayload($company, $definition);
            $order   = new Order();
            $order->forceFill([
                'uuid'                  => (string) Str::uuid(),
                'internal_id'           => 'VROOM-SEED-' . Str::of($seedId)->upper(),
                'company_uuid'          => $company->uuid,
                'payload_uuid'          => $payload->uuid,
                'status'                => 'created',
                'type'                  => 'transport',
                'notes'                 => $definition['name'],
                'orchestrator_priority' => 50,
                'required_skills'       => [],
                'meta'                  => $this->meta($seedId),
            ])->save();

            $this->seedEntities($company, $payload, $definition['entities'] ?? []);

            return [$seedId => $order];
        })->all();
    }

    protected function createPayload(Company $company, array $definition): Payload
    {
        $payload = new Payload();
        $payload->forceFill([
            'uuid'         => (string) Str::uuid(),
            'company_uuid' => $company->uuid,
            'pickup_uuid'  => $definition['pickup']?->uuid,
            'dropoff_uuid' => $definition['dropoff']?->uuid,
            'type'         => 'transport',
            'meta'         => $this->meta($definition['payload_seed_id']),
        ])->save();

        foreach ($definition['waypoints'] as $index => $place) {
            $waypoint = new Waypoint();
            $waypoint->forceFill([
                'uuid'         => (string) Str::uuid(),
                '_import_id'   => static::SEED_NAME . ':waypoint:' . $definition['payload_seed_id'] . ':' . ($index + 1),
                'company_uuid' => $company->uuid,
                'payload_uuid' => $payload->uuid,
                'place_uuid'   => $place->uuid,
                'type'         => 'dropoff',
                'order'        => $index + 1,
                'service_time' => 300,
            ]);

            Waypoint::withoutEvents(fn () => $waypoint->save());
        }

        return $payload;
    }

    protected function seedEntities(Company $company, Payload $payload, array $entities): void
    {
        foreach ($entities as $index => $definition) {
            $entity = new Entity();
            $entity->forceFill([
                'uuid'            => (string) Str::uuid(),
                'company_uuid'    => $company->uuid,
                'payload_uuid'    => $payload->uuid,
                'name'            => $definition['name'],
                'type'            => $definition['type'],
                'weight'          => $definition['weight'],
                'weight_unit'     => $definition['weight_unit'],
                'length'          => $definition['length'],
                'width'           => $definition['width'],
                'height'          => $definition['height'],
                'dimensions_unit' => $definition['dimensions_unit'],
                'meta'            => $this->meta($payload->getMeta('seed_id') . '_' . ($index + 1)),
            ]);

            Entity::withoutEvents(fn () => $entity->save());
        }
    }
}
