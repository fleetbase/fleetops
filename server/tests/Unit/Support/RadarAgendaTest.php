<?php

use Fleetbase\FleetOps\Support\Radar\RadarAgenda;
use Illuminate\Support\Carbon;

function fleetOpsAgendaNow(): Carbon
{
    return Carbon::parse('2026-09-15 08:35:00', 'UTC');
}

function fleetOpsAgendaItem(string $key, array $extra = []): array
{
    [$rule] = explode(':', $key);

    return array_merge([
        'key'        => $key,
        'rule'       => $rule,
        'category'   => 'maintenance',
        'lane'       => 'maintenance',
        'chip'       => 'Maint',
        'severity'   => 'warning',
        'title'      => $key,
        'subject'    => null,
        'due_at'     => null,
        'due_bucket' => 'none',
        'window'     => null,
        'state'      => ['status' => 'open'],
        'actions'    => [],
        'record'     => null,
    ], $extra);
}

function fleetOpsAgendaDriver(string $name, array $extra = []): array
{
    $slug = strtolower(str_replace(' ', '', $name));

    return array_merge(['type' => 'driver', 'uuid' => 'driver-' . $slug, 'public_id' => 'driver_' . $slug, 'label' => $name, 'photo_url' => null], $extra);
}

afterEach(fn () => Carbon::setTestNow());

test('items land in the overdue band, a lane, the later rail or the anytime tray', function () {
    $now   = fleetOpsAgendaNow();
    $items = [
        fleetOpsAgendaItem('maintenance_overdue:a', ['due_at' => '2026-09-12T08:00:00+00:00', 'due_bucket' => 'overdue']),
        fleetOpsAgendaItem('maintenance_due_soon:b', ['due_at' => '2026-09-15T14:00:00+00:00', 'due_bucket' => 'today']),
        fleetOpsAgendaItem('license_expiring:c', ['category' => 'compliance', 'lane' => 'expiries', 'due_at' => '2026-09-21T00:00:00+00:00', 'due_bucket' => 'week']),
        fleetOpsAgendaItem('notice:d', ['category' => 'notices', 'lane' => 'notices', 'due_at' => '2026-09-16T06:00:00+00:00', 'due_bucket' => 'later']),
        fleetOpsAgendaItem('issue_open:e', ['category' => 'issues', 'lane' => 'anytime']),
        fleetOpsAgendaItem('part_low_stock:f', ['category' => 'parts', 'lane' => 'anytime', 'state' => ['status' => 'open', 'planned_at' => '2026-09-15T16:00:00+00:00']]),
        fleetOpsAgendaItem('fuel_unmatched:g', ['category' => 'fuel', 'lane' => 'anytime', 'state' => ['status' => 'snoozed', 'snoozed_until' => '2026-09-18T08:00:00+00:00']]),
    ];

    $day = RadarAgenda::build($items, [], '24h', $now);

    expect($day['window']['key'])->toBe('24h')
        ->and($day['window']['start_at'])->toBe('2026-09-15T08:00:00+00:00')
        ->and($day['window']['end_at'])->toBe('2026-09-16T08:00:00+00:00')
        ->and($day['window']['now_pct'])->toBe(2.43)
        ->and(array_column($day['window']['ticks'], 'label'))->toBe(['08', '10', '12', '14', '16', '18', '20', '22', '00', '02', '04', '06', '08'])
        ->and(array_column($day['overdue'], 'key'))->toBe(['maintenance_overdue:a'])
        ->and(array_column($day['lanes']['maintenance'], 'key'))->toBe(['maintenance_due_soon:b', 'part_low_stock:f'])
        ->and($day['lanes']['maintenance'][0]['pct'])->toBe(25.0)
        ->and($day['lanes']['maintenance'][0]['label'])->toBe('14:00')
        ->and($day['lanes']['maintenance'][1]['planned'])->toBeTrue()
        ->and(array_column($day['lanes']['notices'], 'key'))->toBe(['notice:d'])
        ->and(array_column($day['later'], 'key'))->toBe(['license_expiring:c'])
        ->and(array_column($day['anytime'], 'key'))->toBe(['issue_open:e'], 'snoozed items are not on the agenda')
        ->and($day['counts'])->toBe(['overdue' => 1, 'later' => 1, 'anytime' => 1, 'shifts' => 0]);

    $week = RadarAgenda::build($items, [], '7d', $now);
    expect($week['window']['hours'])->toBe(168)
        ->and(array_column($week['lanes']['expiries'], 'key'))->toBe(['license_expiring:c'], 'the 7-day window absorbs the later rail')
        ->and($week['later'])->toBe([])
        ->and($week['window']['ticks'][1]['label'])->toBe('Wed 16');

    expect(RadarAgenda::build($items, [], 'bogus', $now)['window']['key'])->toBe('24h');
});

test('every live shift is a bar on the shifts lane carrying its gaps and handover', function () {
    $now    = fleetOpsAgendaNow();
    $ortega = fleetOpsAgendaDriver('Luis Ortega');
    $diallo = fleetOpsAgendaDriver('Amara Diallo');
    $items  = [
        fleetOpsAgendaItem('shift_handover:driver_luisortega', ['category' => 'staffing', 'lane' => 'shifts', 'subject' => $ortega, 'window' => ['start_at' => '2026-09-15T01:15:00+00:00', 'end_at' => '2026-09-15T09:15:00+00:00'], 'due_at' => '2026-09-15T09:15:00+00:00', 'due_bucket' => 'today', 'source' => ['active_orders' => 2]]),
        fleetOpsAgendaItem('shift_late_start:driver_amaradiallo', ['category' => 'staffing', 'lane' => 'shifts', 'severity' => 'critical', 'subject' => $diallo, 'window' => ['start_at' => '2026-09-15T08:00:00+00:00', 'end_at' => '2026-09-15T16:00:00+00:00']]),
    ];
    $shifts = [
        ['uuid' => 'sh1', 'public_id' => 'shift_1', 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress', 'driver' => $ortega, 'driver_online' => true, 'active_orders' => 2],
        ['uuid' => 'sh2', 'public_id' => 'shift_2', 'start_at' => '2026-09-15 08:00:00', 'end_at' => '2026-09-15 16:00:00', 'status' => 'scheduled', 'driver' => $diallo, 'driver_online' => false, 'active_orders' => 0],
        ['uuid' => 'sh3', 'public_id' => 'shift_3', 'start_at' => '2026-09-15 14:00:00', 'end_at' => '2026-09-15 21:00:00', 'status' => 'scheduled', 'driver' => fleetOpsAgendaDriver('Tomas Alves'), 'driver_online' => false, 'active_orders' => 0],
        ['uuid' => 'sh4', 'public_id' => 'shift_4', 'start_at' => '2026-09-17 06:00:00', 'end_at' => '2026-09-17 14:00:00', 'status' => 'scheduled', 'driver' => fleetOpsAgendaDriver('Far Away'), 'driver_online' => false, 'active_orders' => 0],
        ['uuid' => 'sh5', 'public_id' => 'shift_5', 'start_at' => '2026-09-14 20:00:00', 'end_at' => '2026-09-15 04:00:00', 'status' => 'completed', 'driver' => fleetOpsAgendaDriver('Night Done'), 'driver_online' => false, 'active_orders' => 0],
    ];

    $day  = RadarAgenda::build($items, $shifts, '24h', $now);
    $lane = collect($day['lanes']['shifts']);
    $bars = $lane->where('kind', 'shift')->keyBy('key');

    expect($bars->keys()->all())->toBe(['shift:shift_1', 'shift:shift_2', 'shift:shift_3'])
        ->and($bars['shift:shift_1']['state'])->toBe('on_shift')
        ->and($bars['shift:shift_1']['pct'])->toBe(0.0)
        ->and($bars['shift:shift_1']['width_pct'])->toBe(5.21)
        ->and($bars['shift:shift_1']['handover_key'])->toBe('shift_handover:driver_luisortega')
        ->and($bars['shift:shift_1']['gap_keys'])->toBe(['shift_handover:driver_luisortega'])
        ->and($bars['shift:shift_2']['state'])->toBe('not_online')
        ->and($bars['shift:shift_2']['severity'])->toBe('critical')
        ->and($bars['shift:shift_3']['state'])->toBe('upcoming')
        ->and($bars['shift:shift_3']['pct'])->toBe(25.0)
        ->and($bars['shift:shift_3']['label'])->toBe('14:00–21:00')
        ->and($lane->where('kind', 'item')->pluck('key')->all())->toBe(['shift_late_start:driver_amaradiallo', 'shift_handover:driver_luisortega'], 'a live gap sits at the now line, ahead of the 09:15 handover')
        ->and($day['counts']['shifts'])->toBe(5);
});

test('a handover card lists the orders and suggests the nearest on-shift driver with capacity', function () {
    $now    = fleetOpsAgendaNow();
    $ortega = fleetOpsAgendaDriver('Luis Ortega');
    $alves  = fleetOpsAgendaDriver('Tomas Alves');
    $kim    = fleetOpsAgendaDriver('Rin Kim');
    $full   = fleetOpsAgendaDriver('Full Driver');
    $early  = fleetOpsAgendaDriver('Ends Early');
    $items  = [
        fleetOpsAgendaItem('shift_handover:driver_luisortega', ['category' => 'staffing', 'lane' => 'shifts', 'subject' => $ortega, 'window' => ['start_at' => '2026-09-15T01:15:00+00:00', 'end_at' => '2026-09-15T09:15:00+00:00'], 'due_at' => '2026-09-15T09:15:00+00:00', 'due_bucket' => 'today', 'source' => ['active_orders' => 2], 'record' => ['route' => 'management.drivers.index.details', 'model' => 'driver_luisortega']]),
    ];
    $shifts = [
        ['uuid' => 'sh1', 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress', 'driver' => $ortega, 'driver_online' => true],
        ['uuid' => 'sh2', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 21:00:00', 'status' => 'in_progress', 'driver' => $alves, 'driver_online' => true],
        ['uuid' => 'sh3', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 18:00:00', 'status' => 'in_progress', 'driver' => $kim, 'driver_online' => true],
        ['uuid' => 'sh4', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 18:00:00', 'status' => 'in_progress', 'driver' => $full, 'driver_online' => true],
        ['uuid' => 'sh5', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 10:00:00', 'status' => 'in_progress', 'driver' => $early, 'driver_online' => true],
    ];
    $drivers = [
        ['uuid' => 'driver-luisortega', 'location' => ['lat' => 40.70, 'lng' => -73.99], 'active_orders' => 2],
        ['uuid' => 'driver-tomasalves', 'location' => ['lat' => 40.71, 'lng' => -73.98], 'active_orders' => 2, 'max_daily_orders' => 6],
        ['uuid' => 'driver-rinkim', 'location' => ['lat' => 40.80, 'lng' => -73.90], 'active_orders' => 0],
        ['uuid' => 'driver-fulldriver', 'location' => ['lat' => 40.70, 'lng' => -73.99], 'active_orders' => 6],
        ['uuid' => 'driver-endsearly', 'location' => ['lat' => 40.70, 'lng' => -73.99], 'active_orders' => 0],
    ];
    $orders = [
        'driver-luisortega' => [
            ['uuid' => 'o1', 'public_id' => 'order_88412', 'status' => 'dispatched', 'destination' => 'Bay Ridge', 'ends_at' => '2026-09-15T09:40:00+00:00'],
            ['uuid' => 'o2', 'public_id' => 'order_88431', 'status' => 'dispatched', 'destination' => 'Sunset Park', 'ends_at' => '2026-09-15T08:50:00+00:00'],
        ],
    ];

    $cards = RadarAgenda::handovers($items, $shifts, $drivers, $orders, $now);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['key'])->toBe('shift_handover:driver_luisortega')
        ->and($cards[0]['shift']['minutes_left'])->toBe(40)
        ->and($cards[0]['rule'])->toBe('shift_handover')
        ->and(array_column($cards[0]['orders'], 'finishes_after_shift'))->toBe([true, false])
        ->and($cards[0]['orders'][0]['destination'])->toBe('Bay Ridge')
        ->and($cards[0]['suggested']['driver']['label'])->toBe('Tomas Alves')
        ->and($cards[0]['suggested']['distance_km'])->toBe(1.4)
        ->and($cards[0]['suggested']['capacity_label'])->toBe('2 of 6 orders')
        ->and($cards[0]['suggested']['on_shift_until'])->toBe('2026-09-15T21:00:00+00:00');

    // Without anyone on shift long enough, the card still exists but has no suggestion.
    $none = RadarAgenda::handovers($items, [$shifts[0], $shifts[4]], $drivers, $orders, $now);
    expect($none[0]['suggested'])->toBeNull();

    expect(RadarAgenda::distanceKm(['lat' => 0, 'lng' => 0], ['lat' => 0, 'lng' => 1]))->toBe(111.2)
        ->and(RadarAgenda::distanceKm(null, ['lat' => 0, 'lng' => 1]))->toBeNull();
});

test('cover suggestions prefer online drivers, then the nearest, then the least busy', function () {
    $now      = fleetOpsAgendaNow();
    $shiftEnd = Carbon::parse('2026-09-15 09:15:00', 'UTC');
    $shift    = fn (string $name, bool $online) => ['uuid' => 'sh-' . $name, 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 21:00:00', 'status' => 'in_progress', 'driver' => fleetOpsAgendaDriver($name), 'driver_online' => $online];

    $offlineFirst = RadarAgenda::suggestCover('driver-leaving', null, $shiftEnd, [$shift('Offline Olga', false), $shift('Online Omar', true)], [], $now);
    expect($offlineFirst['driver']['label'])->toBe('Online Omar');

    // With no locations to compare, the driver carrying fewer orders wins.
    $rows = [
        'driver-busybea'  => ['uuid' => 'driver-busybea', 'active_orders' => 4],
        'driver-freefred' => ['uuid' => 'driver-freefred', 'active_orders' => 1],
    ];
    $leastBusy = RadarAgenda::suggestCover('driver-leaving', null, $shiftEnd, [$shift('Busy Bea', true), $shift('Free Fred', true)], $rows, $now);
    expect($leastBusy['driver']['label'])->toBe('Free Fred');
});

test('a driver with several gaps shows the worst one on their shift bar', function () {
    $now    = fleetOpsAgendaNow();
    $driver = fleetOpsAgendaDriver('Amara Diallo');
    $window = ['start_at' => '2026-09-15T08:00:00+00:00', 'end_at' => '2026-09-15T09:30:00+00:00'];
    $items  = [
        fleetOpsAgendaItem('shift_handover:driver_amaradiallo', ['category' => 'staffing', 'lane' => 'shifts', 'severity' => 'warning', 'subject' => $driver, 'window' => $window]),
        fleetOpsAgendaItem('shift_late_start:driver_amaradiallo', ['category' => 'staffing', 'lane' => 'shifts', 'severity' => 'critical', 'subject' => $driver, 'window' => $window]),
        fleetOpsAgendaItem('driver_without_vehicle:driver_amaradiallo', ['category' => 'staffing', 'lane' => 'anytime', 'severity' => 'info', 'subject' => $driver]),
    ];
    $shifts = [['uuid' => 'sh', 'public_id' => 'shift_a', 'start_at' => '2026-09-15 08:00:00', 'end_at' => '2026-09-15 09:30:00', 'status' => 'scheduled', 'driver' => $driver, 'driver_online' => false]];

    $bar = collect(RadarAgenda::build($items, $shifts, '24h', $now)['lanes']['shifts'])->firstWhere('kind', 'shift');

    expect($bar['severity'])->toBe('critical')
        ->and($bar['handover_key'])->toBe('shift_handover:driver_amaradiallo')
        ->and($bar['gap_keys'])->toHaveCount(3);
});
