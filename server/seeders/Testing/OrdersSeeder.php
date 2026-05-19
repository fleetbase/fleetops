<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Seeders\Testing\Concerns\SeedsTestingData;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrdersSeeder extends Seeder
{
    use SeedsTestingData;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        DB::transaction(function () use ($company) {
            $this->purgeSeedData();
            $this->seedOrders($company);

            $this->command?->info('Seeded FleetOps testing order fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(TrackingStatus::class);
        $this->purgeModel(Order::class);
        $this->purgeModel(Entity::class);
        $this->purgeModel(Waypoint::class);
        $this->purgeModel(TrackingNumber::class);
        $this->purgeModel(Payload::class);
    }

    protected function seedOrders(Company $company): void
    {
        $places = [
            'central_depot'  => $this->seededModel(Place::class, 'central_depot'),
            'west_depot'     => $this->seededModel(Place::class, 'west_depot'),
            'airport_hub'    => $this->seededModel(Place::class, 'airport_hub'),
            'orchard_store'  => $this->seededModel(Place::class, 'orchard_store'),
            'rochor_store'   => $this->seededModel(Place::class, 'rochor_store'),
            'tampines_store' => $this->seededModel(Place::class, 'tampines_store'),
        ];
        $customers = [
            'customer_alice' => $this->seededModel(Contact::class, 'customer_alice'),
            'customer_ben'   => $this->seededModel(Contact::class, 'customer_ben'),
        ];
        $drivers = [
            'driver_ava' => $this->seededModel(Driver::class, 'driver_ava'),
            'driver_ken' => $this->seededModel(Driver::class, 'driver_ken'),
        ];
        $vehicles = [
            'van_central' => $this->seededModel(Vehicle::class, 'van_central'),
            'van_east'    => $this->seededModel(Vehicle::class, 'van_east'),
        ];
        $facilitator = $this->seededModel(Vendor::class, 'facilitator_fastline');
        $dispatcher  = $this->seededModel(User::class, 'dispatcher_user');
        $orderConfig = FleetOps::createTransportConfig($company);

        $orders = [
            'order_created_unassigned' => ['created', null, null, 'customer_alice', 'central_depot', 'orchard_store', [], 2, 1, null, false, false],
            'order_scheduled'          => ['created', null, null, 'customer_ben', 'west_depot', 'rochor_store', [], 4, 1, $this->timestamp(30), false, false],
            'order_dispatched'         => ['dispatched', 'driver_ava', 'van_central', 'customer_alice', 'central_depot', 'orchard_store', ['rochor_store'], 6, 2, null, true, false],
            'order_started'            => ['started', 'driver_ken', 'van_east', 'customer_ben', 'airport_hub', 'tampines_store', [], 8, 1, null, true, true],
            'order_completed'          => ['completed', 'driver_ava', 'van_central', 'customer_alice', 'central_depot', 'orchard_store', [], -4, 1, null, true, true],
            'order_canceled'           => ['canceled', null, null, 'customer_ben', 'central_depot', 'rochor_store', [], -2, 1, null, false, false],
            'order_failed'             => ['failed', 'driver_ken', 'van_east', 'customer_ben', 'airport_hub', 'tampines_store', [], -1, 1, null, true, true],
            'order_vehicle_assigned'   => ['created', null, 'van_central', 'customer_alice', 'west_depot', 'airport_hub', ['orchard_store', 'rochor_store'], 10, 3, null, false, false],
        ];

        foreach ($orders as $seedId => [$status, $driverSeedId, $vehicleSeedId, $customerSeedId, $pickupSeedId, $dropoffSeedId, $waypointSeedIds, $hourOffset, $entityCount, $scheduledAt, $dispatched, $started]) {
            $payload = $this->createPayload($company, $seedId, $places[$pickupSeedId], $places[$dropoffSeedId], array_map(fn ($placeSeedId) => $places[$placeSeedId], $waypointSeedIds));
            $order   = $this->createRecord(Order::class, [
                '_key'                  => $this->fixtureKey($seedId),
                'internal_id'           => 'TEST-' . strtoupper(str_replace('order_', '', $seedId)),
                'company_uuid'          => $company->uuid,
                'payload_uuid'          => $payload->uuid,
                'order_config_uuid'     => $orderConfig->uuid,
                'customer_uuid'         => $customers[$customerSeedId]?->uuid,
                'customer_type'         => Contact::class,
                'facilitator_uuid'      => $facilitator?->uuid,
                'facilitator_type'      => Vendor::class,
                'driver_assigned_uuid'  => $driverSeedId ? $drivers[$driverSeedId]?->uuid : null,
                'vehicle_assigned_uuid' => $vehicleSeedId ? $vehicles[$vehicleSeedId]?->uuid : null,
                'created_by_uuid'       => $dispatcher?->uuid,
                'updated_by_uuid'       => $dispatcher?->uuid,
                'scheduled_at'          => $scheduledAt,
                'dispatched_at'         => $dispatched ? $this->timestamp($hourOffset) : null,
                'dispatched'            => $dispatched,
                'started'               => $started,
                'started_at'            => $started ? $this->timestamp($hourOffset + 1) : null,
                'type'                  => 'transport',
                'status'                => $status,
                'notes'                 => 'FleetOps testing fixture order: ' . $seedId,
                'pod_method'            => 'scan',
                'pod_required'          => in_array($status, ['completed', 'failed'], true),
                'orchestrator_priority' => $status === 'failed' ? 90 : 50,
                'required_skills'       => $vehicleSeedId === 'van_east' ? ['cold_chain'] : [],
                'meta'                  => $this->meta($seedId),
                'created_at'            => $this->timestamp($hourOffset),
                'updated_at'            => $this->timestamp($hourOffset + 1),
            ]);

            $trackingNumber = $this->createTrackingNumber($company, $order, $seedId, $status);
            $order->forceFill(['tracking_number_uuid' => $trackingNumber->uuid])->save();
            $this->seedEntities($company, $payload, $trackingNumber, $seedId, $entityCount, $places[$dropoffSeedId]);
        }
    }

    protected function createPayload(Company $company, string $seedId, ?Place $pickup, ?Place $dropoff, array $waypoints): Payload
    {
        /** @var Payload $payload */
        $payload = $this->createRecord(Payload::class, [
            '_key'         => $this->fixtureKey($seedId . '_payload'),
            'company_uuid' => $company->uuid,
            'pickup_uuid'  => $pickup?->uuid,
            'dropoff_uuid' => $dropoff?->uuid,
            'type'         => 'transport',
            'meta'         => $this->meta($seedId . '_payload'),
        ]);

        foreach ($waypoints as $index => $place) {
            $this->createRecord(Waypoint::class, [
                '_key'         => $this->fixtureKey($seedId . '_waypoint_' . ($index + 1)),
                '_import_id'   => $this->fixtureKey($seedId . ':waypoint:' . ($index + 1)),
                'company_uuid' => $company->uuid,
                'payload_uuid' => $payload->uuid,
                'place_uuid'   => $place?->uuid,
                'type'         => 'dropoff',
                'order'        => $index + 1,
                'service_time' => 300,
                'notes'        => 'FleetOps testing waypoint.',
            ]);
        }

        return $payload;
    }

    protected function createTrackingNumber(Company $company, Order $order, string $seedId, string $status): TrackingNumber
    {
        /** @var TrackingNumber $trackingNumber */
        $trackingNumber = $this->createRecord(TrackingNumber::class, [
            '_key'            => $this->fixtureKey($seedId . '_tracking'),
            'company_uuid'    => $company->uuid,
            'owner_uuid'      => $order->uuid,
            'owner_type'      => Order::class,
            'tracking_number' => 'TEST-' . str_pad((string) crc32($seedId), 10, '0', STR_PAD_LEFT),
            'region'          => 'SG',
        ]);

        $trackingStatus = $this->createRecord(TrackingStatus::class, [
            '_key'                 => $this->fixtureKey($seedId . '_tracking_status'),
            'company_uuid'         => $company->uuid,
            'tracking_number_uuid' => $trackingNumber->uuid,
            'status'               => str_replace('_', ' ', $status),
            'details'              => 'FleetOps testing tracking status for ' . $seedId,
            'code'                 => $status,
            'complete'             => $status === 'completed',
            'city'                 => 'Singapore',
            'country'              => 'SG',
            'location'             => $this->point(1.3048, 103.8318),
            'meta'                 => $this->meta($seedId . '_tracking_status'),
        ]);

        $trackingNumber->forceFill(['status_uuid' => $trackingStatus->uuid])->save();

        return $trackingNumber;
    }

    protected function seedEntities(Company $company, Payload $payload, TrackingNumber $trackingNumber, string $seedId, int $entityCount, ?Place $destination): void
    {
        for ($i = 1; $i <= $entityCount; $i++) {
            $this->createRecord(Entity::class, [
                '_key'                 => $this->fixtureKey($seedId . '_entity_' . $i),
                '_import_id'           => $this->fixtureKey($seedId . ':entity:' . $i),
                'company_uuid'         => $company->uuid,
                'payload_uuid'         => $payload->uuid,
                'tracking_number_uuid' => $trackingNumber->uuid,
                'destination_uuid'     => $destination?->uuid,
                'internal_id'          => 'TEST-ENTITY-' . strtoupper($seedId) . '-' . $i,
                'name'                 => 'Item ' . $i,
                'type'                 => 'parcel',
                'description'          => 'FleetOps testing fixture entity.',
                'weight'               => 5 + $i,
                'weight_unit'          => 'kg',
                'length'               => 40,
                'width'                => 30,
                'height'               => 20,
                'dimensions_unit'      => 'cm',
                'currency'             => 'SGD',
                'declared_value'       => 1000 + ($i * 100),
                'meta'                 => $this->meta($seedId . '_entity_' . $i),
            ]);
        }
    }
}
