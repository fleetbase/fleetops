<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\RecurringOrderScheduleController;
use Fleetbase\FleetOps\Models\RecurringOrderSchedule;
use Illuminate\Support\Carbon;

function normalizeRecurringSeriesInputForTest(array $input, ?RecurringOrderSchedule $existing = null): array
{
    $controller = (new ReflectionClass(RecurringOrderScheduleController::class))->newInstanceWithoutConstructor();
    $method     = new ReflectionMethod(RecurringOrderScheduleController::class, 'normalizeRecurringSeriesInput');

    $method->setAccessible(true);

    return $method->invoke($controller, $input, $existing);
}

it('previews weekly recurring occurrences from an rrule', function () {
    $schedule = new RecurringOrderSchedule([
        'timezone'  => 'Asia/Singapore',
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Asia/Singapore'),
        'rrule'     => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE',
    ]);

    $occurrences = $schedule->previewOccurrences(
        Carbon::parse('2026-05-01 00:00:00', 'Asia/Singapore'),
        Carbon::parse('2026-05-20 23:59:59', 'Asia/Singapore'),
        4
    );

    expect($occurrences->map(fn (Carbon $occurrence) => $occurrence->format('Y-m-d H:i'))->all())
        ->toBe([
            '2026-05-04 09:00',
            '2026-05-06 09:00',
            '2026-05-11 09:00',
            '2026-05-13 09:00',
        ]);
});

it('normalizes recurring series templates without generated entity payloads', function () {
    $input = [
        'name'      => 'Daily replenishment',
        'status'    => 'active',
        'timezone'  => 'UTC',
        'starts_at' => '2026-05-18T07:00:00.000Z',
        'rrule'     => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO',
        'order'     => [
            'customer_uuid'     => 'customer-uuid',
            'customer_type'     => 'fleet-ops:contact',
            'order_config_uuid' => 'config-uuid',
            'type'              => 'transport',
            'payload'           => [
                'pickup'  => [
                    'uuid'            => 'pickup-uuid',
                    'name'            => 'Warehouse',
                    'address'         => '1 Warehouse Road',
                    'street1'         => '1 Warehouse Road',
                    'created_at'      => '2026-01-01T00:00:00.000Z',
                    '_index_resource' => true,
                    'location'        => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
                ],
                'dropoff' => [
                    'uuid'       => 'dropoff-uuid',
                    'name'       => 'Customer',
                    'address'    => '2 Customer Road',
                    'street1'    => '2 Customer Road',
                    'updated_at' => '2026-01-01T00:00:00.000Z',
                    'location'   => ['type' => 'Point', 'coordinates' => [103.9, 1.4]],
                ],
                'entities' => [
                    [
                        'uuid'                 => 'entity-uuid',
                        'public_id'            => 'entity_public_id',
                        'payload_uuid'         => 'payload-uuid',
                        'company_uuid'         => 'company-uuid',
                        'customer_uuid'        => 'customer-uuid',
                        'supplier_uuid'        => 'supplier-uuid',
                        'tracking_number_uuid' => 'tracking-uuid',
                        'barcode'              => str_repeat('x', 1024),
                        'qr_code'              => 'qr-code',
                        'tracking'             => ['status' => 'created'],
                        'name'                 => 'Gift Box',
                        'type'                 => 'parcel',
                        'sku'                  => 'SKU-004',
                        'weight'               => '0.5',
                        'destination_uuid'     => 'dropoff-uuid',
                    ],
                ],
            ],
        ],
    ];

    $normalized = normalizeRecurringSeriesInputForTest($input);
    $entity     = $normalized['template_entities'][0];

    expect($normalized['template_payload']['pickup'])->toHaveKeys(['uuid', 'name', 'address', 'street1', 'location'])
        ->and($normalized['template_payload']['pickup'])->not->toHaveKeys(['created_at', '_index_resource'])
        ->and($entity)->toMatchArray([
            'name'             => 'Gift Box',
            'type'             => 'parcel',
            'sku'              => 'SKU-004',
            'weight'           => '0.5',
            'destination_uuid' => 'dropoff-uuid',
        ])
        ->and($entity)->not->toHaveKeys(['uuid', 'public_id', 'payload_uuid', 'company_uuid', 'customer_uuid', 'supplier_uuid', 'tracking_number_uuid', 'barcode', 'qr_code', 'tracking']);
});

it('normalizes recurring series updates without wiping existing templates', function () {
    $existing = new RecurringOrderSchedule([
        'name'                => 'Existing series',
        'status'              => 'active',
        'timezone'            => 'UTC',
        'starts_at'           => Carbon::parse('2026-05-18T07:00:00.000Z'),
        'rrule'               => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO',
        'customer_uuid'       => 'customer-uuid',
        'customer_type'       => 'fleet-ops:contact',
        'order_config_uuid'   => 'config-uuid',
        'template_order_meta' => [
            'type' => 'transport',
        ],
        'template_payload' => [
            'pickup'   => ['uuid' => 'pickup-uuid', 'name' => 'Warehouse'],
            'dropoff'  => ['uuid' => 'dropoff-uuid', 'name' => 'Customer'],
            'entities' => [],
        ],
        'template_entities' => [
            ['name' => 'Gift Box', 'type' => 'parcel'],
        ],
    ]);

    $normalized = normalizeRecurringSeriesInputForTest([
        'name'  => 'Renamed series',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU',
    ], $existing);

    expect($normalized['name'])->toBe('Renamed series')
        ->and($normalized['rrule'])->toBe('FREQ=WEEKLY;INTERVAL=1;BYDAY=TU')
        ->and($normalized['template_payload']['pickup']['uuid'])->toBe('pickup-uuid')
        ->and($normalized['template_payload']['dropoff']['uuid'])->toBe('dropoff-uuid')
        ->and($normalized['template_entities'][0]['name'])->toBe('Gift Box');
});

it('previews monthly recurring occurrences with monthday', function () {
    $schedule = new RecurringOrderSchedule([
        'timezone'  => 'UTC',
        'starts_at' => Carbon::parse('2026-01-15 08:30:00', 'UTC'),
        'rrule'     => 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=15',
    ]);

    $occurrences = $schedule->previewOccurrences(
        Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        Carbon::parse('2026-04-30 23:59:59', 'UTC'),
        4
    );

    expect($occurrences->map(fn (Carbon $occurrence) => $occurrence->format('Y-m-d H:i'))->all())
        ->toBe([
            '2026-01-15 08:30',
            '2026-02-15 08:30',
            '2026-03-15 08:30',
            '2026-04-15 08:30',
        ]);
});
