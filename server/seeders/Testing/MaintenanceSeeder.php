<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Seeders\Testing\Concerns\SeedsTestingData;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaintenanceSeeder extends Seeder
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
            $equipment = $this->seedEquipment($company);
            $this->seedParts($company);
            $schedules  = $this->seedSchedules($company);
            $workOrders = $this->seedWorkOrders($company, $schedules);
            $this->seedMaintenanceHistory($company, $equipment, $workOrders);

            $this->command?->info('Seeded FleetOps testing maintenance fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(Maintenance::class);
        $this->purgeModel(WorkOrder::class);
        $this->purgeModel(MaintenanceSchedule::class);
        $this->purgeModel(Part::class);
        $this->purgeModel(Equipment::class);
    }

    protected function seedEquipment(Company $company): array
    {
        $vehicle   = $this->seededModel(Vehicle::class, 'van_east');
        $equipment = [
            'equipment_refrigeration_unit' => ['Carrier X4-7500', 'EQ-REF-001', 'refrigeration', 'Carrier', 'X4-7500', $vehicle],
            'equipment_tail_lift'          => ['Dhollandia DH-LM', 'EQ-LIFT-001', 'liftgate', 'Dhollandia', 'DH-LM', $this->seededModel(Vehicle::class, 'truck_maintenance')],
        ];

        $models = [];
        foreach ($equipment as $seedId => [$name, $code, $type, $manufacturer, $model, $vehicle]) {
            $models[$seedId] = $this->createRecord(Equipment::class, [
                '_key'          => $this->fixtureKey($seedId),
                'company_uuid'  => $company->uuid,
                'name'          => $name,
                'code'          => $code,
                'type'          => $type,
                'status'        => 'active',
                'serial_number' => strtoupper($seedId),
                'manufacturer'  => $manufacturer,
                'model'         => $model,
                'equipable_type'=> Vehicle::class,
                'equipable_uuid'=> $vehicle?->uuid,
                'purchased_at'  => $this->timestamp(-2400)->toDateString(),
                'purchase_price'=> $type === 'refrigeration' ? 1240000 : 740000,
                'currency'      => 'SGD',
                'meta'          => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedParts(Company $company): void
    {
        $vendor  = $this->seededModel(Vendor::class, 'supplier_parts');
        $vehicle = $this->seededModel(Vehicle::class, 'truck_maintenance');
        $parts   = [
            'part_oil_filter' => ['OIL-FILTER-001', 'Donaldson P550', 'Donaldson', 'P550', 12, 1800, 'consumable', 'active'],
            'part_brake_pad'  => ['BRAKE-PAD-001', 'Bendix CT-3', 'Bendix', 'CT-3', 3, 8600, 'brake', 'low_stock'],
        ];

        foreach ($parts as $seedId => [$sku, $name, $manufacturer, $model, $quantity, $unitCost, $type, $status]) {
            $this->createRecord(Part::class, [
                '_key'             => $this->fixtureKey($seedId),
                'company_uuid'     => $company->uuid,
                'vendor_uuid'      => $vendor?->uuid,
                'sku'              => $sku,
                'name'             => $name,
                'manufacturer'     => $manufacturer,
                'model'            => $model,
                'description'      => 'FleetOps testing fixture part.',
                'quantity_on_hand' => $quantity,
                'unit_cost'        => $unitCost,
                'msrp'             => $unitCost * 1.3,
                'currency'         => 'SGD',
                'asset_type'       => Vehicle::class,
                'asset_uuid'       => $vehicle?->uuid,
                'type'             => $type,
                'status'           => $status,
                'specs'            => ['fixture' => true],
                'meta'             => $this->meta($seedId),
            ]);
        }
    }

    protected function seedSchedules(Company $company): array
    {
        $driver    = $this->seededModel(Driver::class, 'driver_mira');
        $schedules = [
            'schedule_due_soon' => ['Due Soon Inspection', 'inspection', 'active', 'date', 'months', 1, $this->seededModel(Vehicle::class, 'van_central'), $this->timestamp(168)],
            'schedule_overdue'  => ['Overdue Service', 'service', 'active', 'odometer', 'km', 5000, $this->seededModel(Vehicle::class, 'truck_maintenance'), $this->timestamp(-72)],
        ];

        $models = [];
        foreach ($schedules as $seedId => [$name, $type, $status, $intervalMethod, $intervalUnit, $intervalValue, $vehicle, $nextDueDate]) {
            $models[$seedId] = $this->createRecord(MaintenanceSchedule::class, [
                '_key'                    => $this->fixtureKey($seedId),
                'company_uuid'            => $company->uuid,
                'subject_type'            => Vehicle::class,
                'subject_uuid'            => $vehicle?->uuid,
                'name'                    => $name,
                'type'                    => $type,
                'status'                  => $status,
                'interval_method'         => $intervalMethod,
                'interval_type'           => 'recurring',
                'interval_value'          => $intervalValue,
                'interval_unit'           => $intervalUnit,
                'last_service_odometer'   => 85000,
                'last_service_date'       => $this->timestamp(-720),
                'next_due_date'           => $nextDueDate,
                'next_due_odometer'       => $intervalMethod === 'odometer' ? 90500 : null,
                'default_priority'        => $seedId === 'schedule_overdue' ? 'high' : 'medium',
                'default_assignee_type'   => Driver::class,
                'default_assignee_uuid'   => $driver?->uuid,
                'instructions'            => 'FleetOps testing fixture maintenance schedule.',
                'reminder_offsets'        => [7, 1],
                'meta'                    => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedWorkOrders(Company $company, array $schedules): array
    {
        $driver     = $this->seededModel(Driver::class, 'driver_mira');
        $vehicle    = $this->seededModel(Vehicle::class, 'truck_maintenance');
        $workOrders = [
            'work_order_open'      => ['WO-TEST-OPEN', 'Open Inspection', 'inspection_request', 'open', 'medium', $this->timestamp(-4), $this->timestamp(24), null, 'schedule_due_soon'],
            'work_order_overdue'   => ['WO-TEST-OVERDUE', 'Overdue Service', 'preventive_maintenance', 'open', 'high', $this->timestamp(-120), $this->timestamp(-24), null, 'schedule_overdue'],
            'work_order_completed' => ['WO-TEST-CLOSED', 'Completed Repair', 'general_repair', 'completed', 'low', $this->timestamp(-240), $this->timestamp(-120), $this->timestamp(-96), 'schedule_overdue'],
        ];

        $models = [];
        foreach ($workOrders as $seedId => [$code, $subject, $category, $status, $priority, $openedAt, $dueAt, $closedAt, $scheduleSeedId]) {
            $models[$seedId] = $this->createRecord(WorkOrder::class, [
                '_key'            => $this->fixtureKey($seedId),
                'company_uuid'    => $company->uuid,
                'code'            => $code,
                'subject'         => $subject,
                'category'        => $category,
                'status'          => $status,
                'priority'        => $priority,
                'target_type'     => Vehicle::class,
                'target_uuid'     => $vehicle?->uuid,
                'assignee_type'   => Driver::class,
                'assignee_uuid'   => $driver?->uuid,
                'opened_at'       => $openedAt,
                'due_at'          => $dueAt,
                'closed_at'       => $closedAt,
                'instructions'    => 'FleetOps testing fixture work order.',
                'checklist'       => [
                    ['label' => 'Inspect vehicle', 'completed' => $status === 'completed'],
                    ['label' => 'Record readings', 'completed' => $status === 'completed'],
                ],
                'estimated_cost'  => 25000,
                'approved_budget' => 30000,
                'actual_cost'     => $status === 'completed' ? 22800 : null,
                'currency'        => 'SGD',
                'cost_breakdown'  => ['labor' => 12000, 'parts' => 10800],
                'cost_center'     => 'testing-fixtures',
                'budget_code'     => 'FB-TEST',
                'schedule_uuid'   => $schedules[$scheduleSeedId]?->uuid,
                'meta'            => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedMaintenanceHistory(Company $company, array $equipment, array $workOrders): void
    {
        $driver  = $this->seededModel(Driver::class, 'driver_mira');
        $vehicle = $this->seededModel(Vehicle::class, 'truck_maintenance');
        $records = [
            'maintenance_completed_vehicle'   => [$vehicle, Vehicle::class, 'work_order_completed', 'Completed brake inspection', 'completed'],
            'maintenance_completed_equipment' => [$equipment['equipment_refrigeration_unit'] ?? null, Equipment::class, 'work_order_completed', 'Refrigeration unit calibration', 'completed'],
        ];

        foreach ($records as $seedId => [$maintainable, $maintainableType, $workOrderSeedId, $summary, $status]) {
            $this->createRecord(Maintenance::class, [
                '_key'                => $this->fixtureKey($seedId),
                'company_uuid'        => $company->uuid,
                'work_order_uuid'     => $workOrders[$workOrderSeedId]?->uuid,
                'maintainable_type'   => $maintainableType,
                'maintainable_uuid'   => $maintainable?->uuid,
                'type'                => 'service',
                'status'              => $status,
                'priority'            => 'medium',
                'scheduled_at'        => $this->timestamp(-150),
                'started_at'          => $this->timestamp(-120),
                'completed_at'        => $this->timestamp(-96),
                'odometer'            => 90450,
                'engine_hours'        => 2200,
                'performed_by_type'   => Driver::class,
                'performed_by_uuid'   => $driver?->uuid,
                'summary'             => $summary,
                'notes'               => 'FleetOps testing fixture maintenance history.',
                'line_items'          => [['description' => 'Labor', 'amount' => 12000], ['description' => 'Parts', 'amount' => 10800]],
                'labor_cost'          => 12000,
                'parts_cost'          => 10800,
                'tax'                 => 1824,
                'total_cost'          => 24624,
                'currency'            => 'SGD',
                'attachments'         => [],
                'meta'                => $this->meta($seedId),
            ]);
        }
    }
}
