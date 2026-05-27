<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Seeders\Testing\Concerns\SeedsTestingData;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FleetSeeder extends Seeder
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
            $users    = $this->seedUsers($company);
            $vehicles = $this->seedVehicles($company);
            $drivers  = $this->seedDrivers($company, $users, $vehicles);
            $fleets   = $this->seedFleets($company);
            $this->seedFleetMemberships($fleets, $drivers, $vehicles);
            $this->seedFuelReports($company, $users, $drivers, $vehicles);
            $this->seedIssues($company, $users, $drivers, $vehicles);

            $this->command?->info('Seeded FleetOps testing fleet fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(Issue::class);
        $this->purgeModel(FuelReport::class);
        $this->purgeModel(FleetDriver::class);
        $this->purgeModel(FleetVehicle::class);
        $this->purgeModel(Driver::class);
        $this->purgeModel(Vehicle::class);
        $this->purgeModel(Fleet::class);
        $this->purgeModel(User::class);
    }

    protected function seedUsers(Company $company): array
    {
        $users = [
            'driver_ava_user'  => ['Ava Driver', 'ava.driver.testing@example.test', '+6581000001'],
            'driver_ken_user'  => ['Ken Driver', 'ken.driver.testing@example.test', '+6581000002'],
            'driver_mira_user' => ['Mira Driver', 'mira.driver.testing@example.test', '+6581000003'],
            'dispatcher_user'  => ['Testing Dispatcher', 'dispatcher.testing@example.test', '+6581000004'],
        ];

        $models = [];
        foreach ($users as $seedId => [$name, $email, $phone]) {
            $models[$seedId] = $this->createRecord(User::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'password'     => Hash::make('fleetops-testing'),
                'type'         => str_contains($seedId, 'driver') ? 'driver' : 'user',
                'status'       => 'active',
                'timezone'     => 'Asia/Singapore',
                'country'      => 'SG',
                'meta'         => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedVehicles(Company $company): array
    {
        $vehicles = [
            'van_central'         => ['CEN-01', 'Toyota', 'HiAce', 'GBB-1001', 1.2819, 103.8507, true, 'available', 250, 8, 2, 42],
            'van_east'            => ['EAS-01', 'Nissan', 'NV200', 'GBB-1002', 1.3520, 103.9449, true, 'available', 160, 5, 1, 28],
            'truck_maintenance'   => ['WRK-03', 'Isuzu', 'NPR', 'GBB-1003', 1.3347, 103.7423, false, 'maintenance', 900, 24, 8, 160],
            'bike_unassigned'     => ['RCH-04', 'Honda', 'PCX', 'GBB-1004', 1.3039, 103.8520, false, 'inactive', 25, 1, 0, 6],
            'van_airport'         => ['CGO-05', 'Mercedes-Benz', 'Vito', 'GBB-1005', 1.3644, 103.9915, true, 'available', 220, 7, 2, 36],
            'van_cold_chain'      => ['CLD-06', 'Hyundai', 'H350', 'GBB-1006', 1.3525, 103.9447, true, 'available', 300, 10, 3, 48],
            'truck_west'          => ['JUR-07', 'Hino', '300 Series', 'GBB-1007', 1.3345, 103.7420, true, 'available', 1200, 30, 10, 180],
            'van_linehaul'        => ['LNH-08', 'Ford', 'Transit', 'GBB-1008', 1.3215, 103.7055, false, 'available', 450, 14, 4, 72],
            'van_returns'         => ['RTN-09', 'Peugeot', 'Expert', 'GBB-1009', 1.3150, 103.7649, true, 'available', 180, 6, 1, 32],
            'van_central_2'       => ['CEN-02', 'Toyota', 'HiAce', 'GBB-1010', 1.2871, 103.8550, true, 'available', 250, 8, 2, 42],
            'van_central_3'       => ['CEN-03', 'Nissan', 'NV350', 'GBB-1011', 1.2950, 103.8468, true, 'available', 280, 9, 2, 45],
            'bike_rochor_2'       => ['RCH-11', 'Yamaha', 'NMAX', 'GBB-1012', 1.3042, 103.8528, true, 'available', 25, 1, 0, 6],
            'bike_rochor_3'       => ['RCH-12', 'Honda', 'ADV160', 'GBB-1013', 1.3017, 103.8509, true, 'available', 30, 1, 0, 7],
            'truck_alexandra_1'   => ['ALX-14', 'Mitsubishi Fuso', 'Canter', 'GBB-1014', 1.2868, 103.8054, true, 'available', 850, 20, 6, 120],
            'van_alexandra_2'     => ['ALX-15', 'Ford', 'Transit Custom', 'GBB-1015', 1.2881, 103.8079, false, 'available', 400, 12, 3, 64],
            'van_changi_2'        => ['CGO-16', 'Mercedes-Benz', 'Sprinter', 'GBB-1016', 1.3658, 103.9871, true, 'available', 500, 16, 4, 80],
            'truck_changi_3'      => ['CGO-17', 'Isuzu', 'NPR', 'GBB-1017', 1.3610, 103.9954, true, 'available', 1000, 26, 8, 150],
            'van_tampines_cold_2' => ['CLD-18', 'Hyundai', 'H350', 'GBB-1018', 1.3529, 103.9460, true, 'available', 320, 10, 3, 50],
            'van_tampines_cold_3' => ['CLD-19', 'Toyota', 'HiAce Refrigerated', 'GBB-1019', 1.3498, 103.9425, false, 'maintenance', 260, 8, 2, 40],
            'truck_jurong_2'      => ['JUR-20', 'Hino', '500 Series', 'GBB-1020', 1.3340, 103.7398, true, 'available', 1400, 34, 12, 220],
            'van_jurong_3'        => ['JUR-21', 'Nissan', 'Urvan', 'GBB-1021', 1.3362, 103.7451, true, 'available', 300, 9, 2, 52],
            'truck_linehaul_2'    => ['LNH-22', 'Volvo', 'FL', 'GBB-1022', 1.3210, 103.7032, true, 'available', 1800, 42, 14, 260],
            'van_linehaul_3'      => ['LNH-23', 'Ford', 'Transit', 'GBB-1023', 1.3234, 103.7088, true, 'available', 470, 14, 4, 72],
            'van_returns_2'       => ['RTN-24', 'Peugeot', 'Expert', 'GBB-1024', 1.3168, 103.7660, true, 'available', 180, 6, 1, 32],
            'bike_returns_3'      => ['RTN-25', 'Honda', 'PCX', 'GBB-1025', 1.3136, 103.7629, false, 'inactive', 25, 1, 0, 6],
        ];

        $models = [];
        foreach ($vehicles as $seedId => [$name, $make, $model, $plate, $lat, $lng, $online, $status, $weight, $volume, $pallets, $parcels]) {
            $models[$seedId] = $this->createRecord(Vehicle::class, [
                '_key'                     => $this->fixtureKey($seedId),
                'company_uuid'             => $company->uuid,
                'name'                     => $name,
                'make'                     => $make,
                'model'                    => $model,
                'year'                     => '2025',
                'type'                     => str_contains($seedId, 'bike') ? 'motorcycle' : 'van',
                'status'                   => $status,
                'online'                   => $online,
                'plate_number'             => $plate,
                'location'                 => $this->point($lat, $lng),
                'odometer'                 => str_contains($seedId, 'maintenance') ? 90450 : 24600,
                'odometer_unit'            => 'km',
                'fuel_type'                => str_contains($seedId, 'bike') ? 'petrol' : 'diesel',
                'currency'                 => 'SGD',
                'payload_capacity'         => $weight,
                'payload_capacity_volume'  => $volume,
                'payload_capacity_pallets' => $pallets,
                'payload_capacity_parcels' => $parcels,
                'max_tasks'                => str_contains($seedId, 'bike') ? 4 : 8,
                'return_to_depot'          => false,
                'skills'                   => str_contains($seedId, 'east') ? ['cold_chain'] : [],
                'meta'                     => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedDrivers(Company $company, array $users, array $vehicles): array
    {
        $drivers = [
            'driver_ava'  => ['driver_ava_user', 'van_central', 'S1234567A', 1.2821, 103.8510, true, 'available', ['fragile']],
            'driver_ken'  => ['driver_ken_user', 'van_east', 'S1234567K', 1.3522, 103.9442, true, 'on_duty', ['cold_chain']],
            'driver_mira' => ['driver_mira_user', null, 'S1234567M', 1.3341, 103.7421, false, 'off_duty', []],
        ];

        $models = [];
        foreach ($drivers as $seedId => [$userSeedId, $vehicleSeedId, $license, $lat, $lng, $online, $status, $skills]) {
            $models[$seedId] = $this->createRecord(Driver::class, [
                '_key'                   => $this->fixtureKey($seedId),
                'company_uuid'           => $company->uuid,
                'user_uuid'              => $users[$userSeedId]?->uuid,
                'vehicle_uuid'           => $vehicleSeedId ? $vehicles[$vehicleSeedId]?->uuid : null,
                'drivers_license_number' => $license,
                'license_expiry'         => $this->timestamp()->addYears(2)->toDateString(),
                'location'               => $this->point($lat, $lng),
                'country'                => 'SG',
                'currency'               => 'SGD',
                'city'                   => 'Singapore',
                'online'                 => $online,
                'current_status'         => $status,
                'status'                 => 'available',
                'skills'                 => $skills,
                'max_travel_time'        => 3600,
                'max_distance'           => 60000,
                'meta'                   => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedFleets(Company $company): array
    {
        $fleets = [
            'central_fleet' => ['CityOps', 'transport', '#2563eb', 'cbd_zone', null],
            'east_fleet'    => ['EastLink', 'transport', '#059669', 'airport_zone', null],
            'west_fleet'    => ['WestHub', 'transport', '#ea580c', 'warehouse_zone', null],
        ];

        $models = [];
        foreach ($fleets as $seedId => [$name, $task, $color, $zoneSeedId, $parentSeedId]) {
            $zone            = $this->seededZone($zoneSeedId);
            $models[$seedId] = $this->createRecord(Fleet::class, [
                '_key'              => $this->fixtureKey($seedId),
                'company_uuid'      => $company->uuid,
                'service_area_uuid' => $zone?->service_area_uuid,
                'zone_uuid'         => $zone?->uuid,
                'parent_fleet_uuid' => $parentSeedId ? $models[$parentSeedId]?->uuid : null,
                'name'              => $name,
                'color'             => $color,
                'task'              => $task,
                'status'            => 'active',
            ]);
        }

        $subFleets = [
            'central_cbd_subfleet'       => ['Marina AM', 'last_mile', '#1d4ed8', 'cbd_zone', 'central_fleet'],
            'central_warehouse_subfleet' => ['Alexandra PM', 'middle_mile', '#3b82f6', 'warehouse_zone', 'central_fleet'],
            'east_airport_subfleet'      => ['Changi Cargo', 'airport', '#047857', 'airport_zone', 'east_fleet'],
            'east_cold_chain_subfleet'   => ['Tampines Cold', 'cold_chain', '#10b981', 'airport_zone', 'east_fleet'],
            'west_linehaul_subfleet'     => ['Jurong Linehaul', 'linehaul', '#c2410c', 'warehouse_zone', 'west_fleet'],
            'west_returns_subfleet'      => ['Clementi Returns', 'returns', '#f97316', 'warehouse_zone', 'west_fleet'],
        ];

        foreach ($subFleets as $seedId => [$name, $task, $color, $zoneSeedId, $parentSeedId]) {
            $zone            = $this->seededZone($zoneSeedId);
            $models[$seedId] = $this->createRecord(Fleet::class, [
                '_key'              => $this->fixtureKey($seedId),
                'company_uuid'      => $company->uuid,
                'service_area_uuid' => $zone?->service_area_uuid,
                'zone_uuid'         => $zone?->uuid,
                'parent_fleet_uuid' => $models[$parentSeedId]?->uuid,
                'name'              => $name,
                'color'             => $color,
                'task'              => $task,
                'status'            => 'active',
            ]);
        }

        return $models;
    }

    protected function seedFleetMemberships(array $fleets, array $drivers, array $vehicles): void
    {
        $driverLinks = [
            'central_driver_ava'          => ['central_fleet', 'driver_ava'],
            'central_subfleet_driver_ava' => ['central_cbd_subfleet', 'driver_ava'],
            'east_driver_ken'             => ['east_fleet', 'driver_ken'],
            'east_subfleet_driver_ken'    => ['east_cold_chain_subfleet', 'driver_ken'],
            'west_driver_mira'            => ['west_fleet', 'driver_mira'],
            'west_subfleet_driver_mira'   => ['west_linehaul_subfleet', 'driver_mira'],
        ];

        foreach ($driverLinks as $seedId => [$fleetSeedId, $driverSeedId]) {
            $this->createRecord(FleetDriver::class, [
                '_key'        => $this->fixtureKey($seedId),
                'fleet_uuid'  => $fleets[$fleetSeedId]?->uuid,
                'driver_uuid' => $drivers[$driverSeedId]?->uuid,
            ]);
        }

        $vehicleLinks = [
            'central_vehicle_van'           => ['central_fleet', 'van_central'],
            'central_vehicle_van_2'         => ['central_fleet', 'van_central_2'],
            'central_vehicle_van_3'         => ['central_fleet', 'van_central_3'],
            'central_cbd_vehicle_bike'      => ['central_cbd_subfleet', 'bike_unassigned'],
            'central_cbd_vehicle_bike_2'    => ['central_cbd_subfleet', 'bike_rochor_2'],
            'central_cbd_vehicle_bike_3'    => ['central_cbd_subfleet', 'bike_rochor_3'],
            'central_warehouse_truck'       => ['central_warehouse_subfleet', 'truck_maintenance'],
            'central_warehouse_truck_2'     => ['central_warehouse_subfleet', 'truck_alexandra_1'],
            'central_warehouse_van_3'       => ['central_warehouse_subfleet', 'van_alexandra_2'],
            'east_vehicle_van'              => ['east_fleet', 'van_east'],
            'east_airport_vehicle_van'      => ['east_airport_subfleet', 'van_airport'],
            'east_airport_vehicle_van_2'    => ['east_airport_subfleet', 'van_changi_2'],
            'east_airport_vehicle_truck_3'  => ['east_airport_subfleet', 'truck_changi_3'],
            'east_cold_chain_vehicle_van'   => ['east_cold_chain_subfleet', 'van_cold_chain'],
            'east_cold_chain_vehicle_van_2' => ['east_cold_chain_subfleet', 'van_tampines_cold_2'],
            'east_cold_chain_vehicle_van_3' => ['east_cold_chain_subfleet', 'van_tampines_cold_3'],
            'west_vehicle_truck'            => ['west_fleet', 'truck_west'],
            'west_vehicle_truck_2'          => ['west_fleet', 'truck_jurong_2'],
            'west_vehicle_van_3'            => ['west_fleet', 'van_jurong_3'],
            'west_linehaul_vehicle_van'     => ['west_linehaul_subfleet', 'van_linehaul'],
            'west_linehaul_vehicle_truck_2' => ['west_linehaul_subfleet', 'truck_linehaul_2'],
            'west_linehaul_vehicle_van_3'   => ['west_linehaul_subfleet', 'van_linehaul_3'],
            'west_returns_vehicle_van'      => ['west_returns_subfleet', 'van_returns'],
            'west_returns_vehicle_van_2'    => ['west_returns_subfleet', 'van_returns_2'],
            'west_returns_vehicle_bike_3'   => ['west_returns_subfleet', 'bike_returns_3'],
        ];

        foreach ($vehicleLinks as $seedId => [$fleetSeedId, $vehicleSeedId]) {
            $this->createRecord(FleetVehicle::class, [
                '_key'         => $this->fixtureKey($seedId),
                'fleet_uuid'   => $fleets[$fleetSeedId]?->uuid,
                'vehicle_uuid' => $vehicles[$vehicleSeedId]?->uuid,
            ]);
        }
    }

    protected function seedFuelReports(Company $company, array $users, array $drivers, array $vehicles): void
    {
        $reports = [
            'fuel_van_central' => ['driver_ava', 'van_central', 'Diesel refill after morning route.', 25120, 8450, 42, 'approved', 1.2820, 103.8508],
            'fuel_van_east'    => ['driver_ken', 'van_east', 'Cold chain route refill.', 18420, 6025, 31, 'pending', 1.3521, 103.9444],
        ];

        foreach ($reports as $seedId => [$driverSeedId, $vehicleSeedId, $report, $odometer, $amount, $volume, $status, $lat, $lng]) {
            $this->createRecord(FuelReport::class, [
                'company_uuid'     => $company->uuid,
                'driver_uuid'      => $drivers[$driverSeedId]?->uuid,
                'vehicle_uuid'     => $vehicles[$vehicleSeedId]?->uuid,
                'reported_by_uuid' => $users['dispatcher_user']?->uuid,
                'report'           => $report,
                'odometer'         => $odometer,
                'amount'           => $amount,
                'currency'         => 'SGD',
                'volume'           => $volume,
                'metric_unit'      => 'l',
                'location'         => $this->point($lat, $lng),
                'status'           => $status,
                'meta'             => $this->meta($seedId),
            ]);
        }
    }

    protected function seedIssues(Company $company, array $users, array $drivers, array $vehicles): void
    {
        $issues = [
            'issue_low_tire' => ['driver_ava', 'van_central', 'Low tire pressure warning', 'maintenance', 'medium', 'pending', 1.2822, 103.8512],
            'issue_liftgate' => ['driver_mira', 'truck_maintenance', 'Liftgate inspection required', 'equipment', 'high', 'in-progress', 1.3346, 103.7424],
        ];

        foreach ($issues as $seedId => [$driverSeedId, $vehicleSeedId, $title, $category, $priority, $status, $lat, $lng]) {
            $this->createRecord(Issue::class, [
                'company_uuid'      => $company->uuid,
                'reported_by_uuid'  => $users['dispatcher_user']?->uuid,
                'assigned_to_uuid'  => $users['dispatcher_user']?->uuid,
                'driver_uuid'       => $drivers[$driverSeedId]?->uuid,
                'vehicle_uuid'      => $vehicles[$vehicleSeedId]?->uuid,
                'issue_id'          => strtoupper($seedId),
                'location'          => $this->point($lat, $lng),
                'category'          => $category,
                'type'              => 'inspection',
                'title'             => $title,
                'report'            => $title . ' created as a FleetOps testing fixture.',
                'tags'              => ['fixture', 'fleetops-testing'],
                'priority'          => $priority,
                'status'            => $status,
                'meta'              => $this->meta($seedId),
            ]);
        }
    }
}
