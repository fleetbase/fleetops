<?php

use Fleetbase\FleetOps\Support\Radar\RadarRules;
use Illuminate\Support\Carbon;

/**
 * A fixed morning, so every "3d overdue" and "due in 2 days" is a fact and
 * not a function of when the suite runs.
 */
function fleetOpsRadarNow(): Carbon
{
    return Carbon::parse('2026-09-15 08:35:00', 'UTC');
}

function fleetOpsRadarVehicle(string $id = 'TRK-118', array $extra = []): array
{
    return array_merge([
        'type'      => 'vehicle',
        'class'     => 'Fleetbase\FleetOps\Models\Vehicle',
        'uuid'      => 'vehicle-' . strtolower($id),
        'public_id' => 'vehicle_' . strtolower(str_replace('-', '', $id)),
        'label'     => $id . ' Freightliner Cascadia',
        'photo_url' => null,
    ], $extra);
}

function fleetOpsRadarDriver(string $name = 'Amara Diallo', array $extra = []): array
{
    $slug = strtolower(str_replace(' ', '', $name));

    return array_merge([
        'type'         => 'driver',
        'class'        => 'Fleetbase\FleetOps\Models\Driver',
        'uuid'         => 'driver-' . $slug,
        'public_id'    => 'driver_' . $slug,
        'name'         => $name,
        'label'        => $name,
        'photo_url'    => null,
        'online'       => false,
        'vehicle_uuid' => 'vehicle-trk-118',
    ], $extra);
}

afterEach(fn () => Carbon::setTestNow());

test('maintenance schedules become overdue, due-soon or inspection items with the right due bucket', function () {
    $now      = fleetOpsRadarNow();
    $items    = RadarRules::scheduleItems([
        ['uuid' => 's1', 'public_id' => 'schedule_oil', 'name' => 'Oil change', 'type' => 'oil_change', 'status' => 'active', 'next_due_date' => '2026-09-12 08:00:00', 'next_due_odometer' => 214880, 'has_open_work_order' => false, 'subject' => fleetOpsRadarVehicle()],
        ['uuid' => 's2', 'public_id' => 'schedule_tires', 'name' => 'Tire rotation', 'type' => 'tire_rotation', 'status' => 'active', 'next_due_date' => '2026-09-17 12:00:00', 'has_open_work_order' => true, 'subject' => fleetOpsRadarVehicle('TRK-204')],
        ['uuid' => 's3', 'public_id' => 'schedule_dot', 'name' => 'DOT inspection', 'type' => 'inspection', 'status' => 'active', 'next_due_date' => '2026-09-15 16:00:00', 'subject' => fleetOpsRadarVehicle('VAN-042')],
        ['uuid' => 's4', 'public_id' => 'schedule_far', 'name' => 'Brake service', 'type' => 'brake_service', 'status' => 'active', 'next_due_date' => '2026-10-30 08:00:00', 'subject' => fleetOpsRadarVehicle('TRK-101')],
        ['uuid' => 's5', 'public_id' => 'schedule_paused', 'name' => 'Paused', 'type' => 'oil_change', 'status' => 'paused', 'next_due_date' => '2026-09-01 08:00:00', 'subject' => fleetOpsRadarVehicle('TRK-102')],
    ], $now);

    expect($items)->toHaveCount(3)
        ->and($items[0]['key'])->toBe('maintenance_overdue:schedule_oil')
        ->and($items[0]['severity'])->toBe('critical')
        ->and($items[0]['due_bucket'])->toBe('overdue')
        ->and($items[0]['due_label'])->toBe('3d overdue')
        ->and($items[0]['title'])->toBe('Oil change 3d overdue')
        ->and($items[0]['meta_line'])->toBe('due at 214,880 odometer')
        ->and($items[0]['lane'])->toBe('maintenance')
        ->and($items[0]['chip'])->toBe('Maint')
        ->and($items[0]['record'])->toBe(['route' => 'maintenance.schedules.index.details', 'model' => 'schedule_oil'])
        ->and($items[0]['actions'])->toContain('create_work_order')
        ->and($items[0]['pills'])->toBe(['overdue'])
        ->and($items[1]['rule'])->toBe('maintenance_due_soon')
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['due_bucket'])->toBe('week')
        ->and($items[1]['due_label'])->toBe('Thu 17 Sep')
        ->and($items[1]['title'])->toBe('Tire rotation due in 2 days')
        ->and($items[1]['meta_line'])->toBe('work order open')
        ->and($items[1]['actions'])->not->toContain('create_work_order')
        ->and($items[1]['pills'])->toBe(['due_week'])
        ->and($items[2]['rule'])->toBe('inspection_due')
        ->and($items[2]['category'])->toBe('inspections')
        ->and($items[2]['chip'])->toBe('Inspection')
        ->and($items[2]['due_bucket'])->toBe('today')
        ->and($items[2]['due_label'])->toBe('Today 16:00')
        ->and($items[2]['title'])->toBe('DOT inspection due today')
        ->and($items[2]['pills'])->toBe(['due_week', 'inspections']);
});

test('work orders past due or waiting on something become items', function () {
    $now   = fleetOpsRadarNow();
    $items = RadarRules::workOrderItems([
        ['uuid' => 'w1', 'public_id' => 'work_order_2041', 'code' => 'WO-2041', 'subject' => 'brake pad replacement', 'status' => 'in_progress', 'priority' => 'high', 'due_at' => '2026-09-13 09:00:00', 'target' => fleetOpsRadarVehicle('TRK-207')],
        ['uuid' => 'w2', 'public_id' => 'work_order_2044', 'code' => 'WO-2044', 'subject' => 'DOT inspection', 'status' => 'awaiting_parts', 'priority' => 'normal', 'due_at' => '2026-09-20 09:00:00', 'target' => fleetOpsRadarVehicle('VAN-042')],
        ['uuid' => 'w3', 'public_id' => 'work_order_done', 'code' => 'WO-1', 'subject' => 'done', 'status' => 'completed', 'due_at' => '2026-09-01 09:00:00', 'target' => null],
        ['uuid' => 'w4', 'public_id' => 'work_order_fine', 'code' => 'WO-2', 'subject' => 'on time', 'status' => 'open', 'due_at' => '2026-09-25 09:00:00', 'target' => null],
    ], $now);

    expect($items)->toHaveCount(2)
        ->and($items[0]['key'])->toBe('work_order_overdue:work_order_2041')
        ->and($items[0]['title'])->toBe('WO-2041 brake pad replacement')
        ->and($items[0]['severity'])->toBe('critical')
        ->and($items[0]['due_label'])->toBe('1d overdue')
        ->and($items[0]['meta_line'])->toBe('high priority')
        ->and($items[1]['rule'])->toBe('work_order_blocked')
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['meta_line'])->toBe('awaiting parts')
        ->and($items[1]['due_bucket'])->toBe('week');
});

test('open issues are critical by priority and name the inspection that raised them', function () {
    $now   = fleetOpsRadarNow();
    $items = RadarRules::issueItems([
        ['uuid' => 'i1', 'public_id' => 'issue_1', 'title' => 'Check engine light', 'report' => 'power loss above 55 mph', 'priority' => 'high', 'status' => 'pending', 'vehicle' => fleetOpsRadarVehicle('TRK-311'), 'driver' => null, 'reporter_name' => 'M. Okafor', 'meta' => []],
        ['uuid' => 'i2', 'public_id' => 'issue_2', 'title' => null, 'report' => 'Failed inspection: Truck 7', 'priority' => 'medium', 'status' => 'pending', 'vehicle' => null, 'driver' => fleetOpsRadarDriver(), 'meta' => ['inspection_submission_id' => 'inspection_submission_abc']],
        ['uuid' => 'i3', 'public_id' => 'issue_3', 'title' => 'Closed', 'report' => '', 'priority' => 'low', 'status' => 'resolved', 'vehicle' => null, 'driver' => null, 'meta' => []],
    ], $now);

    expect($items)->toHaveCount(2)
        ->and($items[0]['severity'])->toBe('critical')
        ->and($items[0]['subject']['label'])->toBe('TRK-311 Freightliner Cascadia')
        ->and($items[0]['meta_line'])->toBe('high priority · reported by M. Okafor')
        ->and($items[0]['due_bucket'])->toBe('none')
        ->and($items[0]['lane'])->toBe('anytime')
        ->and($items[0]['actions'][0])->toBe('resolve_issue')
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['title'])->toBe('Failed inspection: Truck 7')
        ->and($items[1]['subject']['type'])->toBe('driver')
        ->and($items[1]['meta_line'])->toBe('medium priority · raised by inspection inspection_submission_abc')
        ->and($items[1]['pills'])->toBe(['issues']);
});

test('inspection submissions become follow-up, unresolved or stale-draft items', function () {
    $now   = fleetOpsRadarNow();
    $items = RadarRules::inspectionItems([
        ['uuid' => 'a', 'public_id' => 'inspection_submission_a', 'status' => 'submitted', 'result' => 'failed', 'failed_items' => 2, 'issue_uuid' => null, 'work_order_uuid' => null, 'resolved_at' => null, 'form_name' => 'Pre-trip DVIR', 'highest_severity' => 'critical', 'vehicle' => fleetOpsRadarVehicle('TRK-207'), 'driver' => null, 'failed_item_labels' => [['label' => 'Brakes', 'severity' => 'critical']]],
        ['uuid' => 'b', 'public_id' => 'inspection_submission_b', 'status' => 'needs_review', 'result' => 'failed', 'failed_items' => 1, 'issue_uuid' => 'issue-1', 'work_order_uuid' => null, 'resolved_at' => null, 'form_name' => 'Pre-trip DVIR', 'highest_severity' => 'medium', 'vehicle' => fleetOpsRadarVehicle('VAN-011'), 'driver' => null],
        ['uuid' => 'c', 'public_id' => 'inspection_submission_c', 'status' => 'submitted', 'result' => 'failed', 'failed_items' => 1, 'issue_uuid' => 'issue-2', 'work_order_uuid' => 'wo-2', 'resolved_at' => null, 'form_name' => 'Post-trip', 'highest_severity' => 'low', 'vehicle' => null, 'driver' => fleetOpsRadarDriver('Priya Nair')],
        ['uuid' => 'd', 'public_id' => 'inspection_submission_d', 'status' => 'draft', 'result' => null, 'failed_items' => 0, 'started_at' => '2026-09-13 07:00:00', 'form_name' => 'Pre-trip DVIR', 'driver_name' => 'Luis Ortega', 'vehicle' => fleetOpsRadarVehicle('TRK-101'), 'driver' => null],
        ['uuid' => 'e', 'public_id' => 'inspection_submission_e', 'status' => 'draft', 'result' => null, 'failed_items' => 0, 'started_at' => '2026-09-15 08:00:00', 'form_name' => 'Pre-trip DVIR', 'vehicle' => null, 'driver' => null],
        ['uuid' => 'f', 'public_id' => 'inspection_submission_f', 'status' => 'submitted', 'result' => 'passed', 'failed_items' => 0, 'issue_uuid' => null, 'work_order_uuid' => null, 'resolved_at' => null, 'form_name' => 'Pre-trip DVIR', 'vehicle' => null, 'driver' => null],
        ['uuid' => 'g', 'public_id' => 'inspection_submission_g', 'status' => 'resolved', 'result' => 'failed', 'failed_items' => 3, 'issue_uuid' => 'i', 'work_order_uuid' => 'w', 'resolved_at' => '2026-09-14', 'form_name' => 'Pre-trip DVIR', 'vehicle' => null, 'driver' => null],
    ], $now);

    expect(array_column($items, 'key'))->toBe([
        'inspection_failed:inspection_submission_a',
        'inspection_failed:inspection_submission_b',
        'inspection_unresolved:inspection_submission_c',
        'inspection_draft:inspection_submission_d',
    ])
        ->and($items[0]['severity'])->toBe('critical')
        ->and($items[0]['title'])->toBe('Pre-trip DVIR failed 2 critical items')
        ->and($items[0]['meta_line'])->toBe('2 failed · no issue · no work order')
        ->and($items[0]['actions'])->toBe(['create_work_order_from_inspection', 'create_issue_from_inspection', 'acknowledge', 'snooze', 'assign', 'open_record'])
        ->and($items[0]['details']['failed_items'][0]['label'])->toBe('Brakes')
        ->and($items[0]['record'])->toBe(['route' => 'maintenance.inspection-submissions.index.details', 'model' => 'inspection_submission_a'])
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['meta_line'])->toBe('1 failed · no work order')
        ->and($items[1]['actions'][0])->toBe('create_work_order_from_inspection')
        ->and($items[2]['severity'])->toBe('info')
        ->and($items[2]['actions'][0])->toBe('resolve_inspection')
        ->and($items[2]['subject']['label'])->toBe('Priya Nair')
        ->and($items[3]['severity'])->toBe('info')
        ->and($items[3]['title'])->toBe('Pre-trip DVIR started 2d ago, never filed')
        ->and($items[3]['meta_line'])->toBe('by Luis Ortega')
        ->and($items[3]['pills'])->toBe(['inspections']);
});

test('inspection links expiring unused become items, expired ones a warning', function () {
    $now   = fleetOpsRadarNow();
    $items = RadarRules::inspectionLinkItems([
        ['uuid' => 'l1', 'public_id' => 'inspection_link_1', 'status' => 'active', 'used_at' => null, 'expires_at' => '2026-09-15 20:00:00', 'last_viewed_at' => '2026-09-15 07:00:00', 'form_name' => 'Pre-trip DVIR', 'form_public_id' => 'inspection_form_1', 'form_uuid' => 'form-1', 'driver' => fleetOpsRadarDriver(), 'vehicle' => null],
        ['uuid' => 'l2', 'public_id' => 'inspection_link_2', 'status' => 'active', 'used_at' => null, 'expires_at' => '2026-09-14 20:00:00', 'last_viewed_at' => null, 'form_name' => 'Pre-trip DVIR', 'form_public_id' => 'inspection_form_1', 'form_uuid' => 'form-1', 'driver' => null, 'vehicle' => fleetOpsRadarVehicle()],
        ['uuid' => 'l3', 'public_id' => 'inspection_link_3', 'status' => 'active', 'used_at' => '2026-09-15 07:30:00', 'expires_at' => '2026-09-15 20:00:00', 'form_name' => 'Pre-trip DVIR', 'form_public_id' => 'inspection_form_1', 'driver' => null, 'vehicle' => null],
        ['uuid' => 'l4', 'public_id' => 'inspection_link_4', 'status' => 'active', 'used_at' => null, 'expires_at' => '2026-09-18 20:00:00', 'form_name' => 'Pre-trip DVIR', 'form_public_id' => 'inspection_form_1', 'driver' => null, 'vehicle' => null],
        ['uuid' => 'l5', 'public_id' => 'inspection_link_5', 'status' => 'revoked', 'used_at' => null, 'expires_at' => '2026-09-15 09:00:00', 'form_name' => 'Pre-trip DVIR', 'form_public_id' => 'inspection_form_1', 'driver' => null, 'vehicle' => null],
    ], $now);

    expect(array_column($items, 'key'))->toBe(['inspection_link_pending:inspection_link_1', 'inspection_link_pending:inspection_link_2'])
        ->and($items[0]['severity'])->toBe('info')
        ->and($items[0]['title'])->toBe('Pre-trip DVIR link expires today, not yet used')
        ->and($items[0]['meta_line'])->toBe('opened, not filed')
        ->and($items[0]['lane'])->toBe('expiries')
        ->and($items[0]['pills'])->toBe(['due_week', 'inspections', 'expiring'])
        ->and($items[0]['record'])->toBe(['route' => 'maintenance.inspection-forms.index.details', 'model' => 'inspection_form_1'])
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['title'])->toBe('Pre-trip DVIR link expired unused')
        ->and($items[1]['meta_line'])->toBe('never opened')
        ->and($items[1]['due_bucket'])->toBe('overdue');
});

test('shifts produce late-start, no-vehicle and handover items only while the shift is live', function () {
    $now    = fleetOpsRadarNow();
    $diallo = fleetOpsRadarDriver('Amara Diallo');
    $nair   = fleetOpsRadarDriver('Priya Nair');
    $ortega = fleetOpsRadarDriver('Luis Ortega');
    $items  = RadarRules::shiftItems([
        ['uuid' => 'sh1', 'public_id' => 'shift_1', 'start_at' => '2026-09-15 08:00:00', 'end_at' => '2026-09-15 16:00:00', 'status' => 'scheduled', 'driver' => $diallo, 'driver_online' => false, 'driver_vehicle_uuid' => 'vehicle-1', 'active_orders' => 0],
        ['uuid' => 'sh2', 'public_id' => 'shift_2', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 14:00:00', 'status' => 'in_progress', 'driver' => $nair, 'driver_online' => true, 'driver_vehicle_uuid' => null, 'active_orders' => 0],
        ['uuid' => 'sh3', 'public_id' => 'shift_3', 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress', 'driver' => $ortega, 'driver_online' => true, 'driver_vehicle_uuid' => 'vehicle-2', 'active_orders' => 2],
        ['uuid' => 'sh4', 'public_id' => 'shift_4', 'start_at' => '2026-09-15 08:25:00', 'end_at' => '2026-09-15 16:00:00', 'status' => 'scheduled', 'driver' => fleetOpsRadarDriver('Just Started'), 'driver_online' => false, 'driver_vehicle_uuid' => 'v', 'active_orders' => 0],
        ['uuid' => 'sh5', 'public_id' => 'shift_5', 'start_at' => '2026-09-15 14:00:00', 'end_at' => '2026-09-15 21:00:00', 'status' => 'scheduled', 'driver' => fleetOpsRadarDriver('Later Today'), 'driver_online' => false, 'driver_vehicle_uuid' => null, 'active_orders' => 3],
        ['uuid' => 'sh6', 'public_id' => 'shift_6', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 16:00:00', 'status' => 'cancelled', 'driver' => fleetOpsRadarDriver('Cancelled Shift'), 'driver_online' => false, 'driver_vehicle_uuid' => null, 'active_orders' => 0],
    ], $now);

    expect(array_column($items, 'key'))->toBe([
        'shift_late_start:driver_amaradiallo',
        'shift_no_vehicle:driver_priyanair',
        'shift_handover:driver_luisortega',
    ])
        ->and($items[0]['title'])->toBe('Amara Diallo scheduled 08:00, not online')
        ->and($items[0]['meta_line'])->toBe('35m late')
        ->and($items[0]['severity'])->toBe('warning')
        ->and($items[0]['lane'])->toBe('shifts')
        ->and($items[0]['window'])->toBe(['start_at' => '2026-09-15T08:00:00+00:00', 'end_at' => '2026-09-15T16:00:00+00:00', 'status' => 'scheduled'])
        ->and($items[0]['actions'][0])->toBe('call')
        ->and($items[0]['pills'])->toBe(['shifts'])
        ->and($items[1]['title'])->toBe('Priya Nair on shift since 06:00, no vehicle assigned')
        ->and($items[1]['meta_line'])->toBe('2h 35m on shift')
        ->and($items[1]['actions'][0])->toBe('assign_vehicle')
        ->and($items[1]['pills'])->toBe(['unassigned', 'shifts'])
        ->and($items[2]['title'])->toBe('Luis Ortega shift ends in 40m, 2 active orders still assigned')
        ->and($items[2]['due_bucket'])->toBe('today')
        ->and($items[2]['source']['active_orders'])->toBe(2)
        ->and($items[2]['actions'][0])->toBe('handover')
        ->and($items[2]['chip'])->toBe('Handover');

    $lateByAnHour = RadarRules::shiftItems([
        ['uuid' => 'sh7', 'public_id' => 'shift_7', 'start_at' => '2026-09-15 07:30:00', 'end_at' => '2026-09-15 16:00:00', 'status' => 'confirmed', 'driver' => $diallo, 'driver_online' => false, 'driver_vehicle_uuid' => 'v', 'active_orders' => 0],
    ], $now);

    expect($lateByAnHour[0]['severity'])->toBe('critical')
        ->and($lateByAnHour[0]['meta_line'])->toBe('1h 5m late');
});

test('drivers without a vehicle are skipped while on shift and licences expiring are flagged', function () {
    $now    = fleetOpsRadarNow();
    $shifts = [
        ['uuid' => 'sh', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 14:00:00', 'status' => 'in_progress', 'driver' => ['uuid' => 'driver-priyanair']],
    ];
    $items = RadarRules::driverItems([
        fleetOpsRadarDriver('Priya Nair', ['vehicle_uuid' => null, 'online' => true]),
        fleetOpsRadarDriver('Sam Bauer', ['vehicle_uuid' => null, 'online' => false, 'license_expiry' => '2026-10-06', 'drivers_license_number' => 'D-4471']),
        fleetOpsRadarDriver('Ben Novak', ['vehicle_uuid' => 'v', 'online' => true, 'license_expiry' => '2026-09-10']),
        fleetOpsRadarDriver('Fine Driver', ['vehicle_uuid' => 'v', 'license_expiry' => '2027-01-01']),
    ], $shifts, $now);

    expect(array_column($items, 'key'))->toBe([
        'driver_without_vehicle:driver_sambauer',
        'license_expiring:driver_sambauer',
        'license_expiring:driver_bennovak',
    ])
        ->and($items[0]['severity'])->toBe('info')
        ->and($items[0]['title'])->toBe('Sam Bauer has no vehicle assigned')
        ->and($items[0]['pills'])->toBe(['unassigned'])
        ->and($items[1]['title'])->toBe('Sam Bauer licence expires 6 Oct')
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['meta_line'])->toBe('licence D-4471')
        ->and($items[1]['due_bucket'])->toBe('later')
        ->and($items[1]['lane'])->toBe('expiries')
        ->and($items[1]['pills'])->toBe(['expiring'])
        ->and($items[2]['title'])->toBe('Ben Novak licence expired 10 Sep')
        ->and($items[2]['severity'])->toBe('critical')
        ->and($items[2]['due_bucket'])->toBe('overdue');
});

test('vehicles report failed inspections, no driver, no device and lease expiry', function () {
    $now     = fleetOpsRadarNow();
    $devices = [['uuid' => 'd1', 'attachable_uuid' => null]];
    $items   = RadarRules::vehicleItems([
        ['uuid' => 'vehicle-1', 'public_id' => 'vehicle_1', 'label' => 'TRK-311', 'status' => 'inspection_failed', 'driver_uuid' => 'driver-1', 'device_count' => 1],
        ['uuid' => 'vehicle-2', 'public_id' => 'vehicle_2', 'label' => 'VAN-042', 'status' => 'available', 'driver_uuid' => null, 'device_count' => 0, 'lease_expires_at' => '2026-09-30'],
        ['uuid' => 'vehicle-3', 'public_id' => 'vehicle_3', 'label' => 'TRK-101', 'status' => 'maintenance', 'driver_uuid' => null, 'device_count' => 2],
    ], $devices, $now);

    expect(array_column($items, 'key'))->toBe([
        'vehicle_inspection_failed:vehicle_1',
        'vehicle_without_driver:vehicle_2',
        'vehicle_without_device:vehicle_2',
        'lease_expiring:vehicle_2',
    ])
        ->and($items[0]['severity'])->toBe('critical')
        ->and($items[0]['record']['route'])->toBe('management.vehicles.index.details.inspections')
        ->and($items[0]['pills'])->toBe(['inspections'])
        ->and($items[1]['title'])->toBe('VAN-042 has no driver')
        ->and($items[1]['meta_line'])->toBe('lease ends 30 Sep')
        ->and($items[1]['chip'])->toBe('Idle')
        ->and($items[2]['meta_line'])->toBe('1 spare device')
        ->and($items[2]['category'])->toBe('connectivity')
        ->and($items[3]['title'])->toBe('VAN-042 lease ends 30 Sep')
        ->and($items[3]['due_bucket'])->toBe('later');

    $noSpares = RadarRules::vehicleItems([
        ['uuid' => 'vehicle-2', 'public_id' => 'vehicle_2', 'label' => 'VAN-042', 'status' => 'available', 'driver_uuid' => 'd', 'device_count' => 0],
    ], [], $now);

    expect($noSpares)->toBe([]);
});

test('parts use the reorder point, then the spec threshold, then five, and go critical when a work order needs them', function () {
    expect(RadarRules::lowStockThreshold(['reorder_point' => 6, 'specs' => ['low_stock_threshold' => 9]]))->toBe(6)
        ->and(RadarRules::lowStockThreshold(['reorder_point' => 0, 'specs' => ['low_stock_threshold' => 9]]))->toBe(9)
        ->and(RadarRules::lowStockThreshold(['reorder_point' => null, 'specs' => '{"low_stock_threshold":3}']))->toBe(3)
        ->and(RadarRules::lowStockThreshold(['reorder_point' => null, 'specs' => null]))->toBe(5);

    $now        = fleetOpsRadarNow();
    $workOrders = [
        ['code' => 'WO-2041', 'status' => 'open', 'subject' => 'brake pad replacement', 'checklist' => [['title' => 'Fit brake pads (PN 8842)']]],
        ['code' => 'WO-9', 'status' => 'completed', 'subject' => 'wipers', 'checklist' => [['title' => 'wiper blades']]],
    ];
    $items = RadarRules::partItems([
        ['uuid' => 'p1', 'public_id' => 'part_pads', 'name' => 'Brake pads', 'sku' => 'PN 8842', 'quantity_on_hand' => 2, 'reorder_point' => 6],
        ['uuid' => 'p2', 'public_id' => 'part_wipers', 'name' => 'Wiper blades', 'sku' => 'WB-1', 'quantity_on_hand' => 0, 'reorder_point' => 4],
        ['uuid' => 'p3', 'public_id' => 'part_fine', 'name' => 'Filters', 'sku' => 'F-1', 'quantity_on_hand' => 20, 'reorder_point' => 6],
    ], $workOrders, $now);

    expect(array_column($items, 'key'))->toBe(['part_low_stock:part_pads', 'part_low_stock:part_wipers'])
        ->and($items[0]['title'])->toBe('Brake pads — 2 left, reorder point 6')
        ->and($items[0]['meta_line'])->toBe('reorder point 6 · blocks WO-2041')
        ->and($items[0]['severity'])->toBe('warning')
        ->and($items[1]['title'])->toBe('Wiper blades — out of stock')
        ->and($items[1]['severity'])->toBe('warning')
        ->and($items[1]['pills'])->toBe(['low_stock']);

    $critical = RadarRules::partItems([
        ['uuid' => 'p1', 'public_id' => 'part_pads', 'name' => 'Brake pads', 'sku' => 'PN 8842', 'quantity_on_hand' => 0, 'reorder_point' => 6],
    ], $workOrders, $now);

    expect($critical[0]['severity'])->toBe('critical');
});

test('unmatched fuel transactions carry the suggested vehicle when an identity column matches', function () {
    $now      = fleetOpsRadarNow();
    $vehicles = [
        ['uuid' => 'vehicle-207', 'public_id' => 'vehicle_207', 'label' => 'TRK-207 Isuzu NPR', 'plate_number' => 'ABC-207', 'vin' => 'VIN207', 'fuel_card_number' => '4471'],
    ];

    expect(RadarRules::suggestVehicleForFuel(['vehicle_card_id' => '4471'], $vehicles))->toBe(['uuid' => 'vehicle-207', 'public_id' => 'vehicle_207', 'label' => 'TRK-207 Isuzu NPR', 'matched' => 'fuel_card_number'])
        ->and(RadarRules::suggestVehicleForFuel(['plate_number' => 'abc-207'], $vehicles)['matched'])->toBe('plate_number')
        ->and(RadarRules::suggestVehicleForFuel(['vin' => 'nope'], $vehicles))->toBeNull();

    $items = RadarRules::fuelItems([
        ['uuid' => 'f1', 'public_id' => 'fuel_provider_transaction_1', 'amount' => 21240, 'currency' => 'USD', 'station_name' => 'Pilot #331', 'transaction_at' => '2026-09-14 08:04:00', 'vehicle_card_id' => '4471', 'sync_status' => 'unmatched', 'suggested_vehicle' => ['label' => 'TRK-207 Isuzu NPR']],
        ['uuid' => 'f2', 'public_id' => 'fuel_provider_transaction_2', 'amount' => 5000, 'currency' => 'USD', 'station_name' => 'Shell', 'sync_status' => 'matched'],
    ], $now);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('USD 212.40 at Pilot #331 — no vehicle matched')
        ->and($items[0]['meta_line'])->toBe('card 4471 · suggest: TRK-207 Isuzu NPR · 1d ago')
        ->and($items[0]['actions'][0])->toBe('match_vehicle')
        ->and($items[0]['pills'])->toBe(['fuel']);
});

test('notices, devices and trailers become items', function () {
    $now = fleetOpsRadarNow();

    $notices = RadarRules::noticeItems([
        ['uuid' => 'n1', 'public_id' => 'alert_notice', 'message' => 'Yard closed Saturday for repaving', 'severity' => 'warning', 'status' => 'open', 'meta' => ['due_at' => '2026-09-18 18:00:00', 'scope' => 'Yard 3 · whole fleet'], 'state' => ['status' => 'acknowledged', 'acknowledged_at' => '2026-09-15T08:12:00+00:00']],
        ['uuid' => 'n2', 'public_id' => 'alert_done', 'message' => 'Old', 'severity' => 'info', 'status' => 'resolved', 'meta' => []],
    ], $now);

    expect($notices)->toHaveCount(1)
        ->and($notices[0]['key'])->toBe('notice:alert_notice')
        ->and($notices[0]['due_bucket'])->toBe('week')
        ->and($notices[0]['lane'])->toBe('notices')
        ->and($notices[0]['meta_line'])->toBe('Yard 3 · whole fleet')
        ->and($notices[0]['actions'])->toBe(['resolve', 'acknowledge', 'snooze', 'assign'])
        ->and($notices[0]['pills'])->toBe(['due_week', 'notices']);

    $devices = RadarRules::deviceItems([
        ['uuid' => 'd1', 'public_id' => 'device_1', 'name' => 'GPS-477', 'attachable_uuid' => null, 'online' => true],
        ['uuid' => 'd2', 'public_id' => 'device_2', 'name' => 'GPS-478', 'attachable_uuid' => 'vehicle-1', 'online' => true],
    ], $now);

    expect($devices)->toHaveCount(1)
        ->and($devices[0]['title'])->toBe('GPS-477 not attached to a vehicle')
        ->and($devices[0]['meta_line'])->toBe('online')
        ->and($devices[0]['actions'][0])->toBe('attach_device');

    $trailers = RadarRules::trailerItems([
        ['uuid' => 't1', 'public_id' => 'trailer_1', 'label' => 'TRL-019', 'lease_expires_at' => '2026-09-20'],
        ['uuid' => 't2', 'public_id' => 'trailer_2', 'label' => 'TRL-004', 'lease_expires_at' => '2027-09-20'],
    ], $now);

    expect($trailers)->toHaveCount(1)
        ->and($trailers[0]['key'])->toBe('lease_expiring:trailer_1')
        ->and($trailers[0]['subject']['type'])->toBe('trailer')
        ->and($trailers[0]['record']['route'])->toBe('management.trailers.index.details');
});

test('build merges state, sorts overdue first, counts pills over open items and summarises', function () {
    $now   = fleetOpsRadarNow();
    $built = RadarRules::build([
        'schedules' => [
            ['uuid' => 's1', 'public_id' => 'schedule_oil', 'name' => 'Oil change', 'type' => 'oil_change', 'status' => 'active', 'next_due_date' => '2026-09-12 08:00:00', 'subject' => fleetOpsRadarVehicle()],
            ['uuid' => 's2', 'public_id' => 'schedule_tires', 'name' => 'Tire rotation', 'type' => 'tire_rotation', 'status' => 'active', 'next_due_date' => '2026-09-17 12:00:00', 'subject' => fleetOpsRadarVehicle('TRK-204')],
        ],
        'issues' => [
            ['uuid' => 'i1', 'public_id' => 'issue_1', 'title' => 'Check engine light', 'priority' => 'high', 'status' => 'pending', 'vehicle' => fleetOpsRadarVehicle('TRK-311'), 'driver' => null, 'meta' => []],
            ['uuid' => 'i2', 'public_id' => 'issue_2', 'title' => 'Scratch', 'priority' => 'low', 'status' => 'pending', 'vehicle' => null, 'driver' => null, 'meta' => []],
        ],
        'parts' => [
            ['uuid' => 'p1', 'public_id' => 'part_pads', 'name' => 'Brake pads', 'quantity_on_hand' => 2, 'reorder_point' => 6],
        ],
        'states' => [
            'maintenance_due_soon:schedule_tires' => ['status' => 'snoozed', 'snoozed_until' => '2026-09-18T08:00:00+00:00', 'snoozed_by_name' => 'M. Reyes'],
            'issue_open:issue_1'                  => ['status' => 'acknowledged', 'acknowledged_at' => '2026-09-15T08:12:00+00:00', 'acknowledged_by_name' => 'You'],
            'part_low_stock:part_pads'            => ['status' => 'snoozed', 'snoozed_until' => '2026-09-14T08:00:00+00:00'],
        ],
    ], $now);

    // Dated first by due date; undated by severity, then title — so the
    // warning-level part sorts before the low-priority issue.
    expect(array_column($built['items'], 'key'))->toBe([
        'maintenance_overdue:schedule_oil',
        'maintenance_due_soon:schedule_tires',
        'issue_open:issue_1',
        'part_low_stock:part_pads',
        'issue_open:issue_2',
    ])
        ->and($built['items'][1]['state']['status'])->toBe('snoozed')
        ->and($built['items'][1]['state']['snoozed_until'])->toBe('2026-09-18T08:00:00+00:00')
        ->and($built['items'][1]['state']['snoozed_by_name'])->toBe('M. Reyes')
        ->and($built['items'][2]['state']['status'])->toBe('acknowledged')
        ->and($built['items'][3]['state']['status'])->toBe('open')
        ->and($built['items'][3]['state']['snoozed_until'])->toBeNull()
        ->and($built['counts']['overdue'])->toBe(1)
        ->and($built['counts']['due_week'])->toBe(0)
        ->and($built['counts']['issues'])->toBe(2)
        ->and($built['counts']['low_stock'])->toBe(1)
        ->and($built['summary'])->toBe(['open' => 4, 'acknowledged' => 1, 'snoozed' => 1, 'overdue' => 1, 'critical' => 2, 'undated' => 3]);

    $open = RadarRules::forTab($built['items'], 'open', $now);
    expect(array_column($open, 'key'))->not->toContain('maintenance_due_soon:schedule_tires')
        ->and(array_column(RadarRules::forTab($built['items'], 'snoozed', $now), 'key'))->toBe(['maintenance_due_soon:schedule_tires'])
        ->and(array_column(RadarRules::forPills($open, ['issues']), 'key'))->toBe(['issue_open:issue_1', 'issue_open:issue_2'])
        ->and(RadarRules::forPills($open, ['issues', 'overdue']))->toBe([])
        ->and(RadarRules::forPills($open, ['not-a-pill']))->toHaveCount(4)
        ->and(array_column(RadarRules::search($open, 'trk-311'), 'key'))->toBe(['issue_open:issue_1'])
        ->and(array_column(RadarRules::search($open, 'brake'), 'key'))->toBe(['part_low_stock:part_pads'])
        ->and(array_column(RadarRules::group($open), 'key'))->toBe(['overdue', 'none'])
        ->and(RadarRules::group($open)[1]['count'])->toBe(3)
        ->and(RadarRules::paginate($open, 2, 3)['items'])->toHaveCount(1)
        ->and(RadarRules::paginate($open, 2, 3)['meta'])->toBe(['total' => 4, 'page' => 2, 'limit' => 3, 'pages' => 2]);
});

test('keys parse only for known rules and buckets follow the calendar', function () {
    $now = fleetOpsRadarNow();

    expect(RadarRules::parseKey('issue_open:issue_1'))->toBe(['issue_open', 'issue_1'])
        ->and(RadarRules::parseKey('notice:alert_x'))->toBe(['notice', 'alert_x'])
        ->and(RadarRules::parseKey('nope:issue_1'))->toBeNull()
        ->and(RadarRules::parseKey('issue_open:'))->toBeNull()
        ->and(RadarRules::parseKey('issue_open'))->toBeNull()
        ->and(RadarRules::parseKey(null))->toBeNull()
        ->and(RadarRules::stateTypes())->not->toContain('notice')
        ->and(RadarRules::bucket(null, $now))->toBe('none')
        ->and(RadarRules::bucket(Carbon::parse('2026-09-15 08:34:59', 'UTC'), $now))->toBe('overdue')
        ->and(RadarRules::bucket(Carbon::parse('2026-09-15 23:00:00', 'UTC'), $now))->toBe('today')
        ->and(RadarRules::bucket(Carbon::parse('2026-09-22 08:35:00', 'UTC'), $now))->toBe('week')
        ->and(RadarRules::bucket(Carbon::parse('2026-09-22 08:35:01', 'UTC'), $now))->toBe('later')
        ->and(RadarRules::dueLabel(Carbon::parse('2026-09-15 08:00:00', 'UTC'), $now))->toBe('35m overdue')
        ->and(RadarRules::dueLabel(Carbon::parse('2026-09-15 03:00:00', 'UTC'), $now))->toBe('5h overdue')
        ->and(RadarRules::dueLabel(Carbon::parse('2026-10-01 03:00:00', 'UTC'), $now))->toBe('1 Oct')
        ->and(RadarRules::dueInWords(Carbon::parse('2026-09-16 03:00:00', 'UTC'), $now))->toBe('tomorrow')
        ->and(RadarRules::minutesWords(125))->toBe('2h 5m')
        ->and(RadarRules::minutesWords(120))->toBe('2h')
        ->and(RadarRules::carbon('not a date'))->toBeNull()
        ->and(RadarRules::carbon(new DateTimeImmutable('2026-09-15 00:00:00'))->toDateString())->toBe('2026-09-15');
});

test('normalizeState turns an ended snooze back into open or acknowledged', function () {
    $now = fleetOpsRadarNow();

    expect(RadarRules::normalizeState(null, $now)['status'])->toBe('open')
        ->and(RadarRules::normalizeState(['status' => 'snoozed', 'snoozed_until' => '2026-09-15T08:00:00+00:00'], $now)['status'])->toBe('open')
        ->and(RadarRules::normalizeState(['status' => 'snoozed', 'snoozed_until' => '2026-09-15T08:00:00+00:00', 'acknowledged_at' => 'x'], $now)['status'])->toBe('acknowledged')
        ->and(RadarRules::normalizeState(['status' => 'open', 'snoozed_until' => '2026-09-15T09:00:00+00:00'], $now)['status'])->toBe('snoozed')
        ->and(RadarRules::normalizeState(['status' => 'resolved'], $now)['status'])->toBe('resolved');
});
