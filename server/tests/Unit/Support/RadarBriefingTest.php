<?php

use Fleetbase\FleetOps\Support\Radar\RadarBriefing;
use Fleetbase\FleetOps\Support\Radar\RadarRules;
use Illuminate\Support\Carbon;

function fleetOpsBriefNow(): Carbon
{
    return Carbon::parse('2026-09-15 08:35:00', 'UTC');
}

function fleetOpsBriefVehicle(string $id): array
{
    return ['type' => 'vehicle', 'class' => 'Fleetbase\FleetOps\Models\Vehicle', 'uuid' => 'vehicle-' . strtolower($id), 'public_id' => 'vehicle_' . strtolower($id), 'label' => $id, 'photo_url' => null];
}

function fleetOpsBriefDriver(string $name, array $extra = []): array
{
    $slug = strtolower(str_replace(' ', '', $name));

    return array_merge(['type' => 'driver', 'class' => 'Fleetbase\FleetOps\Models\Driver', 'uuid' => 'driver-' . $slug, 'public_id' => 'driver_' . $slug, 'name' => $name, 'label' => $name, 'photo_url' => null, 'online' => true, 'vehicle_uuid' => null], $extra);
}

/**
 * The morning from the design concept, as RadarRules sees it.
 */
function fleetOpsBriefItems(): array
{
    $now = fleetOpsBriefNow();

    return RadarRules::build([
        'schedules' => [
            ['uuid' => 's1', 'public_id' => 'schedule_oil', 'name' => 'Oil change', 'type' => 'oil_change', 'status' => 'active', 'next_due_date' => '2026-09-12 08:00:00', 'has_open_work_order' => false, 'subject' => fleetOpsBriefVehicle('TRK-118')],
            ['uuid' => 's2', 'public_id' => 'schedule_tires', 'name' => 'Tire rotation', 'type' => 'tire_rotation', 'status' => 'active', 'next_due_date' => '2026-09-17 12:00:00', 'has_open_work_order' => true, 'subject' => fleetOpsBriefVehicle('TRK-204')],
        ],
        'workOrders' => [
            ['uuid' => 'w1', 'public_id' => 'work_order_2041', 'code' => 'WO-2041', 'subject' => 'brake pad replacement', 'status' => 'in_progress', 'priority' => 'high', 'due_at' => '2026-09-13 09:00:00', 'target' => fleetOpsBriefVehicle('TRK-207'), 'checklist' => [['title' => 'Fit brake pads (PN 8842)']]],
        ],
        'inspectionSubmissions' => [
            ['uuid' => 'a', 'public_id' => 'inspection_submission_a', 'status' => 'submitted', 'result' => 'failed', 'failed_items' => 2, 'issue_uuid' => null, 'work_order_uuid' => null, 'resolved_at' => null, 'form_name' => 'Pre-trip DVIR', 'highest_severity' => 'critical', 'vehicle' => fleetOpsBriefVehicle('VAN-042'), 'driver' => null],
        ],
        'shifts' => [
            ['uuid' => 'sh1', 'public_id' => 'shift_1', 'start_at' => '2026-09-15 08:00:00', 'end_at' => '2026-09-15 16:00:00', 'status' => 'scheduled', 'driver' => fleetOpsBriefDriver('Amara Diallo'), 'driver_online' => false, 'driver_vehicle_uuid' => 'v', 'active_orders' => 0],
            ['uuid' => 'sh2', 'public_id' => 'shift_2', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 14:00:00', 'status' => 'in_progress', 'driver' => fleetOpsBriefDriver('Priya Nair'), 'driver_online' => true, 'driver_vehicle_uuid' => null, 'active_orders' => 0],
            ['uuid' => 'sh3', 'public_id' => 'shift_3', 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress', 'driver' => fleetOpsBriefDriver('Luis Ortega'), 'driver_online' => true, 'driver_vehicle_uuid' => 'v2', 'active_orders' => 2],
        ],
        'vehicles' => [
            ['uuid' => 'vehicle-van042', 'public_id' => 'vehicle_van042', 'label' => 'VAN-042', 'status' => 'available', 'driver_uuid' => null, 'device_count' => 1, 'lease_expires_at' => '2026-09-30'],
        ],
        'drivers' => [
            fleetOpsBriefDriver('Sam Bauer', ['online' => false, 'license_expiry' => '2026-10-06']),
        ],
        'parts' => [
            ['uuid' => 'p1', 'public_id' => 'part_pads', 'name' => 'Brake pads', 'sku' => 'PN 8842', 'quantity_on_hand' => 2, 'reorder_point' => 6],
        ],
        'fuelTransactions' => [
            ['uuid' => 'f1', 'public_id' => 'fuel_provider_transaction_1', 'amount' => 21240, 'currency' => 'USD', 'station_name' => 'Pilot #331', 'transaction_at' => '2026-09-14 08:04:00', 'vehicle_card_id' => '4471', 'sync_status' => 'unmatched', 'suggested_vehicle' => ['uuid' => 'vehicle-trk207', 'public_id' => 'vehicle_trk207', 'label' => 'TRK-207', 'matched' => 'fuel_card_number']],
        ],
        'states' => [
            'lease_expiring:vehicle_van042' => ['status' => 'snoozed', 'snoozed_until' => '2026-09-18T08:00:00+00:00'],
        ],
    ], $now)['items'];
}

afterEach(fn () => Carbon::setTestNow());

test('category rows score the open gaps with capped, visible arithmetic', function () {
    $brief = RadarBriefing::build(fleetOpsBriefItems(), fleetOpsBriefNow());
    $rows  = collect($brief['categories'])->keyBy('key');

    // Maintenance: oil overdue (10) + tires due soon (4) + WO-2041 overdue (10) = 24 off.
    expect($rows['maintenance']['score'])->toBe(76)
        ->and($rows['maintenance']['count'])->toBe(3)
        ->and($rows['maintenance']['critical'])->toBe(2)
        ->and(array_column($rows['maintenance']['gaps'], 'label'))->toBe(['1 overdue', '1 work order overdue', '1 due this week'])
        ->and($rows['maintenance']['filter'])->toBe(['category' => 'maintenance'])
        // Inspections: one critical failed inspection.
        ->and($rows['inspections']['score'])->toBe(90)
        ->and($rows['inspections']['gaps'][0]['label'])->toBe('1 failed, no follow-up')
        // Staffing: late start (4) + no vehicle (4) + handover (4) + idle vehicle (1) + offline driver without vehicle (1) = 14.
        ->and($rows['staffing']['score'])->toBe(86)
        ->and(array_column($rows['staffing']['gaps'], 'rule'))->toContain('shift_late_start', 'shift_no_vehicle', 'shift_handover', 'vehicle_without_driver')
        // Compliance: the snoozed lease is not open; only the licence counts.
        ->and($rows['compliance']['score'])->toBe(96)
        ->and($rows['compliance']['gaps'])->toBe([['rule' => 'license_expiring', 'count' => 1, 'label' => '1 licence expiring']])
        ->and($rows['fuel']['score'])->toBe(96)
        ->and($rows['parts']['score'])->toBe(96)
        ->and($rows['connectivity']['score'])->toBe(100)
        ->and($rows['issues']['score'])->toBe(100)
        ->and($brief['score']['value'])->toBe((int) round((76 + 90 + 86 + 96 + 96 + 96 + 100 + 100) / 8))
        ->and($brief['score']['delta'])->toBeNull()
        ->and($brief['score']['summary'])->toBe('2 overdue · 2 due this week · 3 unassigned · 1 inspection items')
        ->and($brief['open'])->toBe(12);

    // One rule cannot zero a category on its own.
    $many = [];
    for ($i = 0; $i < 20; $i++) {
        $many[] = ['key' => "issue_open:i{$i}", 'rule' => 'issue_open', 'category' => 'issues', 'severity' => 'critical', 'state' => ['status' => 'open'], 'title' => 'x', 'due_bucket' => 'none', 'pills' => ['issues']];
    }
    $capped = collect(RadarBriefing::categories($many))->firstWhere('key', 'issues');
    expect($capped['score'])->toBe(100 - RadarBriefing::RULE_CAP);
});

test('the delta compares with the most recent earlier day and the history keeps 30 days', function () {
    $now     = fleetOpsBriefNow();
    $history = [
        ['date' => '2026-09-13', 'score' => 70],
        ['date' => '2026-09-14', 'score' => 82],
        ['date' => '2026-09-15', 'score' => 50],
        ['date' => '2026-09-16', 'score' => 99],
    ];

    expect(RadarBriefing::delta(78, $history, $now))->toBe(-4)
        ->and(RadarBriefing::delta(78, [], $now))->toBeNull()
        ->and(RadarBriefing::delta(78, [['date' => '2026-09-15', 'score' => 10]], $now))->toBeNull();

    $pushed = RadarBriefing::pushHistory($history, 78, $now);
    expect(array_column($pushed, 'score'))->toBe([70, 82, 78, 99])
        ->and($pushed[2])->toBe(['date' => '2026-09-15', 'score' => 78]);

    $long = [];
    for ($day = 1; $day <= 40; $day++) {
        $long[] = ['date' => sprintf('2026-08-%02d', $day), 'score' => $day];
    }
    expect(RadarBriefing::pushHistory($long, 1, $now))->toHaveCount(30);
});

test('the brief names the records behind the morning, with links, and closes with routine', function () {
    $brief     = RadarBriefing::build(fleetOpsBriefItems(), fleetOpsBriefNow());
    $sentences = array_map(fn ($sentence) => implode('', array_column($sentence, 'text')), $brief['brief']);

    expect($sentences)->toHaveCount(4)
        ->and($sentences[0])->toBe('2 jobs are past due: TRK-118 (3d overdue) and TRK-207 (1d overdue). 1 failed inspection has no follow-up yet: VAN-042 2 failed · no issue · no work order.')
        ->and($sentences[1])->toBe('Amara Diallo is 35m late for a shift that started 08:00, Priya Nair has been on shift 2h 35m on shift without a vehicle, and Luis Ortega ends at 09:15 still holding 2 orders.')
        ->and($sentences[2])->toBe('1 licence or lease expires within 30 days; 1 fuel transaction is unmatched (1 with a suggested vehicle); 1 part is below reorder point, 1 blocking a work order.')
        ->and($sentences[3])->toBe('Everything else is routine.');

    $links = array_filter($brief['brief'][0], fn ($segment) => isset($segment['route']));
    expect(array_values($links)[0])->toBe(['text' => 'TRK-118', 'route' => 'maintenance.schedules.index.details', 'model' => 'schedule_oil']);

    expect(RadarBriefing::brief([], fleetOpsBriefNow()))->toBe([[['text' => 'All clear: nothing needs a decision this morning.']]]);
});

test('decisions are one click each, most urgent first, and say what the click calls', function () {
    $brief     = RadarBriefing::build(fleetOpsBriefItems(), fleetOpsBriefNow());
    $decisions = collect($brief['decisions'])->keyBy('key');

    expect(array_column($brief['decisions'], 'key'))->toBe([
        'inspection_follow_up:inspection_submission_a',
        'open_work_order:schedule_oil',
        'assign_vehicle:driver_priyanair',
        'match_fuel:fuel_provider_transaction_1',
    ]);

    $followUp = $decisions['inspection_follow_up:inspection_submission_a'];
    expect($followUp['severity'])->toBe('critical')
        ->and($followUp['title'])->toBe('Raise a work order for VAN-042')
        ->and($followUp['confirm'])->toBe(['label' => 'Raise work order', 'action' => 'create_work_order_from_inspection', 'method' => 'POST', 'endpoint' => 'inspection-submissions/inspection_submission_a/create-work-order', 'body' => []])
        ->and($followUp['alternatives'][0]['endpoint'])->toBe('inspection-submissions/inspection_submission_a/create-issue')
        ->and($followUp['keys'])->toBe(['inspection_failed:inspection_submission_a'])
        ->and($followUp['record']['route'])->toBe('maintenance.inspection-submissions.index.details');

    $workOrder = $decisions['open_work_order:schedule_oil'];
    expect($workOrder['title'])->toBe('Open a work order for TRK-118')
        ->and($workOrder['confirm']['endpoint'])->toBe('maintenance-schedules/schedule_oil/trigger');

    $assign = $decisions['assign_vehicle:driver_priyanair'];
    expect($assign['severity'])->toBe('warning')
        ->and($assign['title'])->toBe('Assign VAN-042 to Priya Nair')
        ->and($assign['subtitle'])->toBe('clears 2 gaps')
        ->and($assign['confirm']['endpoint'])->toBe('drivers/driver-priyanair/assign-vehicle')
        ->and($assign['confirm']['body'])->toBe(['vehicle' => 'vehicle-van042'])
        ->and($assign['confirm']['undo']['endpoint'])->toBe('drivers/driver-priyanair/unassign-vehicle')
        ->and($assign['alternatives'][0]['action'])->toBe('pick_vehicle')
        ->and($assign['keys'])->toBe(['shift_no_vehicle:driver_priyanair', 'vehicle_without_driver:vehicle_van042']);

    $fuel = $decisions['match_fuel:fuel_provider_transaction_1'];
    expect($fuel['severity'])->toBe('info')
        ->and($fuel['title'])->toBe('Match USD 212.40 at Pilot #331 to TRK-207')
        ->and($fuel['subtitle'])->toBe('matched by fuel card number')
        ->and($fuel['confirm']['body'])->toBe(['vehicle' => 'vehicle-trk207'])
        ->and($fuel['alternatives'][0]['body'])->toBe(['status' => 'ignored']);
});

test('the brief and decisions cope with thin mornings', function () {
    $now = fleetOpsBriefNow();

    expect(RadarBriefing::score([]))->toBe(100);

    $overdue = [];
    foreach (['A', 'B', 'C'] as $letter) {
        $overdue[] = ['uuid' => 's' . $letter, 'public_id' => 'schedule_' . $letter, 'name' => 'Service', 'type' => 'oil_change', 'status' => 'active', 'next_due_date' => '2026-09-12 08:00:00', 'has_open_work_order' => true, 'subject' => fleetOpsBriefVehicle('TRK-' . $letter)];
    }
    $items = RadarRules::build([
        'schedules'             => $overdue,
        // A failed inspection that already has both follow-ups offers no decision.
        'inspectionSubmissions' => [['uuid' => 'x', 'public_id' => 'inspection_submission_x', 'status' => 'submitted', 'result' => 'failed', 'failed_items' => 1, 'issue_uuid' => null, 'work_order_uuid' => null, 'resolved_at' => null, 'form_name' => 'DVIR', 'highest_severity' => 'low', 'vehicle' => null, 'driver' => null]],
        // Two drivers need a vehicle but only one is idle.
        'shifts'   => [
            ['uuid' => 'sh1', 'public_id' => 'shift_1', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 14:00:00', 'status' => 'in_progress', 'driver' => fleetOpsBriefDriver('One Driver'), 'driver_online' => true, 'driver_vehicle_uuid' => null, 'active_orders' => 0],
            ['uuid' => 'sh2', 'public_id' => 'shift_2', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 14:00:00', 'status' => 'in_progress', 'driver' => fleetOpsBriefDriver('Two Driver'), 'driver_online' => true, 'driver_vehicle_uuid' => null, 'active_orders' => 0],
        ],
        'vehicles' => [['uuid' => 'vehicle-idle', 'public_id' => 'vehicle_idle', 'label' => 'IDLE-1', 'status' => 'available', 'driver_uuid' => null, 'device_count' => 1]],
    ], $now)['items'];

    $items = array_map(fn ($item) => $item['rule'] === 'inspection_failed' ? array_merge($item, ['actions' => ['acknowledge']]) : $item, $items);

    $brief     = RadarBriefing::build($items, $now);
    $sentences = array_map(fn ($sentence) => implode('', array_column($sentence, 'text')), $brief['brief']);

    expect($sentences[0])->toBe('3 jobs are past due: TRK-A (3d overdue) and TRK-B (3d overdue) and 1 more. 1 failed inspection has no follow-up yet: a vehicle 1 failed · no issue · no work order.')
        ->and($sentences)->toHaveCount(3, 'no housekeeping sentence when nothing expires, fuel is matched and stock is fine')
        ->and(array_column($brief['decisions'], 'key'))->toBe(['assign_vehicle:driver_onedriver']);
});
