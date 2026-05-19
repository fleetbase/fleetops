<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\ServiceRateFee;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Seeders\Testing\Concerns\SeedsTestingData;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NetworkSeeder extends Seeder
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
            FleetOps::createTransportConfig($company);
            $this->seedServiceAreas($company);
            $this->seedZones($company);
            $places = $this->seedPlaces($company);
            $this->seedContacts($company, $places);
            $vendors = $this->seedVendors($company, $places);
            $this->seedServiceRates($company, $vendors);

            $this->command?->info('Seeded FleetOps testing network fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(ServiceRateFee::class);
        $this->purgeModel(ServiceRate::class);
        $this->purgeModel(Vendor::class);
        $this->purgeModel(Contact::class);
        $this->purgeModel(Place::class);
        $this->purgeModel(Zone::class);
        $this->purgeModel(ServiceArea::class);
    }

    protected function seedServiceAreas(Company $company): void
    {
        $areas = [
            'central_service_area' => [
                'name'   => 'Singapore Central',
                'border' => [[1.2700, 103.8000], [1.2700, 103.9100], [1.3600, 103.9100], [1.3600, 103.8000]],
                'color'  => '#2563eb',
            ],
            'east_service_area' => [
                'name'   => 'Singapore East',
                'border' => [[1.3050, 103.9000], [1.3050, 104.0200], [1.3950, 104.0200], [1.3950, 103.9000]],
                'color'  => '#059669',
            ],
        ];

        foreach ($areas as $seedId => $area) {
            $this->createRecord(ServiceArea::class, [
                '_key'                    => $this->fixtureKey($seedId),
                'company_uuid'            => $company->uuid,
                'name'                    => $area['name'],
                'type'                    => 'city',
                'country'                 => 'SG',
                'border'                  => $this->multiPolygon($area['border']),
                'color'                   => $area['color'],
                'stroke_color'            => $area['color'],
                'status'                  => 'active',
                'trigger_on_entry'        => true,
                'trigger_on_exit'         => true,
                'dwell_threshold_minutes' => 15,
                'speed_limit_kmh'         => 50,
            ]);
        }
    }

    protected function seedZones(Company $company): void
    {
        $zones = [
            'cbd_zone' => [
                'service_area' => 'central_service_area',
                'name'         => 'Marina Bay',
                'border'       => [[1.2760, 103.8350], [1.2760, 103.8650], [1.3050, 103.8650], [1.3050, 103.8350]],
                'color'        => '#7c3aed',
            ],
            'airport_zone' => [
                'service_area' => 'east_service_area',
                'name'         => 'Changi',
                'border'       => [[1.3350, 103.9600], [1.3350, 104.0100], [1.3850, 104.0100], [1.3850, 103.9600]],
                'color'        => '#dc2626',
            ],
            'warehouse_zone' => [
                'service_area' => 'central_service_area',
                'name'         => 'Jurong',
                'border'       => [[1.3000, 103.7800], [1.3000, 103.8300], [1.3450, 103.8300], [1.3450, 103.7800]],
                'color'        => '#ea580c',
            ],
        ];

        foreach ($zones as $seedId => $zone) {
            $serviceArea = $this->seededServiceArea($zone['service_area']);
            $this->createRecord(Zone::class, [
                '_key'                    => $this->fixtureKey($seedId),
                'company_uuid'            => $company->uuid,
                'service_area_uuid'       => $serviceArea?->uuid,
                'name'                    => $zone['name'],
                'description'             => 'Fixture zone for FleetOps testing assertions.',
                'border'                  => $this->polygon($zone['border']),
                'color'                   => $zone['color'],
                'stroke_color'            => $zone['color'],
                'status'                  => 'active',
                'trigger_on_entry'        => true,
                'trigger_on_exit'         => true,
                'dwell_threshold_minutes' => 10,
                'speed_limit_kmh'         => 40,
            ]);
        }
    }

    protected function seedPlaces(Company $company): array
    {
        $places = [
            'central_depot' => ['Raffles Quay', '1 Raffles Quay', 'Singapore', 'SG', 1.2816, 103.8510],
            'west_depot'    => ['Jurong East', '2 Jurong East Street 21', 'Singapore', 'SG', 1.3345, 103.7420],
            'airport_hub'   => ['Changi Airfreight', '80 Airport Boulevard', 'Singapore', 'SG', 1.3644, 103.9915],
            'orchard_store' => ['ION Orchard', '2 Orchard Turn', 'Singapore', 'SG', 1.3048, 103.8318],
            'rochor_store'  => ['Rochor Canal', '1 Rochor Canal Road', 'Singapore', 'SG', 1.3039, 103.8520],
            'tampines_store'=> ['Tampines Mall', '4 Tampines Central 5', 'Singapore', 'SG', 1.3525, 103.9447],
        ];

        $models = [];
        foreach ($places as $seedId => [$name, $street1, $city, $country, $lat, $lng]) {
            $models[$seedId] = $this->createRecord(Place::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'name'         => $name,
                'street1'      => $street1,
                'city'         => $city,
                'country'      => $country,
                'location'     => $this->point($lat, $lng),
                'meta'         => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedContacts(Company $company, array $places): void
    {
        $contacts = [
            'customer_alice' => ['Alice Tan', 'customer', 'alice.testing@example.test', '+6591000001', 'orchard_store'],
            'customer_ben'   => ['Ben Lim', 'customer', 'ben.testing@example.test', '+6591000002', 'rochor_store'],
            'ops_manager'    => ['Nadia Rahman', 'contact', 'nadia.testing@example.test', '+6591000003', 'central_depot'],
        ];

        foreach ($contacts as $seedId => [$name, $type, $email, $phone, $placeSeedId]) {
            $this->createRecord(Contact::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'place_uuid'   => $places[$placeSeedId]?->uuid,
                'name'         => $name,
                'title'        => $type === 'customer' ? 'Receiving Contact' : 'Operations Manager',
                'email'        => $email,
                'phone'        => $phone,
                'type'         => $type,
                'notes'        => 'FleetOps testing fixture contact.',
                'meta'         => $this->meta($seedId),
            ]);
        }
    }

    protected function seedVendors(Company $company, array $places): array
    {
        $vendors = [
            'facilitator_fastline' => ['Fastline Logistics', 'facilitator', 'ops@fastline.example.test', '+6592000001', 'central_depot'],
            'supplier_parts'       => ['Apex Parts Supply', 'supplier', 'parts@example.test', '+6592000002', 'west_depot'],
        ];

        $models = [];
        foreach ($vendors as $seedId => [$name, $type, $email, $phone, $placeSeedId]) {
            $models[$seedId] = $this->createRecord(Vendor::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'place_uuid'   => $places[$placeSeedId]?->uuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'country'      => 'SG',
                'status'       => 'active',
                'type'         => $type,
                'notes'        => 'FleetOps testing fixture vendor.',
                'meta'         => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedServiceRates(Company $company, array $vendors): void
    {
        $orderConfig = FleetOps::createTransportConfig($company);
        $rates       = [
            'same_day_cbd'    => ['Same Day CBD', 'same_day', 'central_service_area', 'cbd_zone', 1200, 120, 1],
            'airport_express' => ['Airport Express', 'express', 'east_service_area', 'airport_zone', 2800, 180, 0],
        ];

        foreach ($rates as $seedId => [$name, $type, $areaSeedId, $zoneSeedId, $baseFee, $perMeter, $estimatedDays]) {
            $serviceRate = $this->createRecord(ServiceRate::class, [
                '_key'                    => $this->fixtureKey($seedId),
                'company_uuid'            => $company->uuid,
                'service_area_uuid'       => $this->seededServiceArea($areaSeedId)?->uuid,
                'zone_uuid'               => $this->seededZone($zoneSeedId)?->uuid,
                'order_config_uuid'       => $orderConfig->uuid,
                'service_name'            => $name,
                'service_type'            => $type,
                'base_fee'                => $baseFee,
                'per_meter_flat_rate_fee' => $perMeter,
                'per_meter_unit'          => 'm',
                'algorithm'               => 'fixed_meter',
                'rate_calculation_method' => 'fixed_meter',
                'currency'                => 'SGD',
                'duration_terms'          => 'days',
                'estimated_days'          => $estimatedDays,
            ]);

            foreach ([['parcel', 1, 5, 400], ['parcel', 6, 20, 900]] as $index => [$unit, $min, $max, $fee]) {
                $this->createRecord(ServiceRateFee::class, [
                    '_key'              => $this->fixtureKey($seedId . '_fee_' . ($index + 1)),
                    'service_rate_uuid' => $serviceRate->uuid,
                    'min'               => $min,
                    'max'               => $max,
                    'unit'              => $unit,
                    'fee'               => $fee,
                    'currency'          => 'SGD',
                ]);
            }
        }
    }
}
