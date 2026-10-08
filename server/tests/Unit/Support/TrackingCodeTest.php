<?php

use Fleetbase\FleetOps\Support\TrackingCode;

beforeEach(function () {
    $this->previousConsoleConfig = config('fleetbase.console');
    config(['fleetbase.console' => ['host' => 'console.fleetbase.test', 'subdomain' => null, 'secure' => true]]);
});

afterEach(function () {
    config(['fleetbase.console' => $this->previousConsoleConfig]);
});

test('qr content is a versioned tracking url naming the owner by public id', function () {
    expect(TrackingCode::qrContent('ACM1234567890SG', 'order_a1b2c3d'))
        ->toBe('https://console.fleetbase.test/~/track-order?order=ACM1234567890SG&r=order_a1b2c3d&v=1')
        // No owner: the tracking number alone still resolves.
        ->and(TrackingCode::qrContent('ACM1234567890SG'))
        ->toBe('https://console.fleetbase.test/~/track-order?order=ACM1234567890SG&v=1')
        // Tracking numbers start with the company name, which may hold spaces.
        ->and(TrackingCode::qrContent('A B1234567890SG', ''))
        ->toBe('https://console.fleetbase.test/~/track-order?order=A+B1234567890SG&v=1');
});

test('qr content follows the configured console origin but never the app environment', function () {
    config(['fleetbase.console' => ['host' => 'fleetbase.test', 'subdomain' => 'console', 'secure' => false]]);
    expect(TrackingCode::qrContent('TN1', 'order_x'))->toBe('http://console.fleetbase.test/~/track-order?order=TN1&r=order_x&v=1');

    config(['fleetbase.console' => ['host' => 'https://tracking.example.test/', 'subdomain' => 'ignored']]);
    expect(TrackingCode::qrContent('TN1'))->toBe('https://tracking.example.test/~/track-order?order=TN1&v=1');

    // Unset `secure` defaults to https, whatever environment generated the label.
    config(['fleetbase.console' => ['host' => 'console.fleetbase.test']]);
    expect(TrackingCode::qrContent('TN1'))->toStartWith('https://console.fleetbase.test/');
});

test('barcode content is the bare tracking number printed beneath it', function () {
    expect(TrackingCode::barcodeContent('ACM1234567890SG'))->toBe('ACM1234567890SG');
});

test('parse reads every code format in circulation', function () {
    $empty = ['uuid' => null, 'tracking_number' => null, 'public_id' => null, 'reference' => null, 'version' => null];

    // Generated codes round-trip.
    expect(TrackingCode::parse(TrackingCode::qrContent('ACM1234567890SG', 'waypoint_a1b2c3d')))->toBe(array_merge($empty, [
        'tracking_number' => 'ACM1234567890SG',
        'public_id'       => 'waypoint_a1b2c3d',
        'version'         => 1,
    ]))
        // Legacy labels encode the owner's bare uuid.
        ->and(TrackingCode::parse(' 6f1c2a4e-8b3d-4c5f-9a7e-1d2c3b4a5f60 '))->toBe(array_merge($empty, [
            'uuid' => '6f1c2a4e-8b3d-4c5f-9a7e-1d2c3b4a5f60',
        ]))
        // A barcode, or a value typed by hand, may be a tracking number or a public id.
        ->and(TrackingCode::parse('ACM1234567890SG'))->toBe(array_merge($empty, ['reference' => 'ACM1234567890SG']))
        ->and(TrackingCode::parse(''))->toBe($empty)
        ->and(TrackingCode::parse(null))->toBe($empty);
});

test('parse ignores host and path and tolerates hostile or partial urls', function () {
    // Any host, a `tn` alias, and a tracking number carried in a deeper path.
    expect(TrackingCode::parse('HTTPS://old-domain.example/track-order?tn=TN9&r=entity_e1&v=2'))->toMatchArray([
        'tracking_number' => 'TN9',
        'public_id'       => 'entity_e1',
        'version'         => 2,
    ])
        ->and(TrackingCode::parse('https://console.example/~/track/TN%2042?r=order_o1'))->toMatchArray([
            'tracking_number' => 'TN 42',
            'public_id'       => 'order_o1',
            'version'         => null,
        ])
        // The tracking page's own path is not mistaken for a tracking number.
        ->and(TrackingCode::parse('https://console.example/track-order?r=order_o1'))->toMatchArray([
            'tracking_number' => null,
            'public_id'       => 'order_o1',
        ])
        // Array or blank parameters and a non-numeric version are dropped, not trusted.
        ->and(TrackingCode::parse('https://console.example/track-order?order[]=a&r=%20&v=1x'))->toMatchArray([
            'tracking_number' => null,
            'public_id'       => null,
            'version'         => null,
        ])
        // parse_url() rejects this outright.
        ->and(TrackingCode::parse('http:///track-order'))->toMatchArray([
            'tracking_number' => null,
            'public_id'       => null,
            'version'         => null,
        ]);
});

test('matches accepts legacy and new codes for the scanned subject only', function () {
    $order = (object) [
        'uuid'           => '6f1c2a4e-8b3d-4c5f-9a7e-1d2c3b4a5f60',
        'public_id'      => 'order_a1b2c3d',
        'trackingNumber' => (object) ['tracking_number' => 'ACM1234567890SG'],
    ];

    expect(TrackingCode::matches('6f1c2a4e-8b3d-4c5f-9a7e-1d2c3b4a5f60', $order))->toBeTrue()
        ->and(TrackingCode::matches('00000000-0000-4000-8000-000000000000', $order))->toBeFalse()
        ->and(TrackingCode::matches(TrackingCode::qrContent('ACM1234567890SG', 'order_a1b2c3d'), $order))->toBeTrue()
        // Handhelds may not preserve case.
        ->and(TrackingCode::matches('acm1234567890sg', $order))->toBeTrue()
        ->and(TrackingCode::matches('order_a1b2c3d', $order))->toBeTrue()
        ->and(TrackingCode::matches('order_other', $order))->toBeFalse()
        ->and(TrackingCode::matches(TrackingCode::qrContent('ACM1234567890SG'), $order))->toBeTrue()
        ->and(TrackingCode::matches('https://console.example/track-order?r=order_a1b2c3d', $order))->toBeTrue()
        // The owner named in the code decides: another owner is refused even with this
        // subject's tracking number, while a second tracking number issued for this
        // subject still names it and matches.
        ->and(TrackingCode::matches(TrackingCode::qrContent('ACM1234567890SG', 'order_other'), $order))->toBeFalse()
        ->and(TrackingCode::matches(TrackingCode::qrContent('ACM0000000000SG', 'order_a1b2c3d'), $order))->toBeTrue()
        // Without an owner, the number must be this subject's.
        ->and(TrackingCode::matches(TrackingCode::qrContent('ACM0000000000SG'), $order))->toBeFalse()
        // A URL that names nothing matches nothing.
        ->and(TrackingCode::matches('https://console.example/track-order', $order))->toBeFalse()
        ->and(TrackingCode::matches('', $order))->toBeFalse()
        // A request may carry a scanner's whole result object instead of its value.
        ->and(TrackingCode::matches(['type' => 'qr', 'value' => 'order_a1b2c3d'], $order))->toBeFalse()
        ->and(TrackingCode::matches(null, $order))->toBeFalse()
        ->and(TrackingCode::matches('order_a1b2c3d', null))->toBeFalse()
        ->and(TrackingCode::matches('order_a1b2c3d', (object) ['public_id' => 'order_a1b2c3d']))->toBeFalse();
});

test('matches decides a subject without its own tracking number by public id', function () {
    // A service stop resolves to a place, which has no tracking number of its own.
    $place = (object) ['uuid' => 'place-uuid', 'public_id' => 'place_p1'];

    expect(TrackingCode::matches(TrackingCode::qrContent('ACM1234567890SG', 'place_p1'), $place))->toBeTrue()
        ->and(TrackingCode::matches(TrackingCode::qrContent('ACM1234567890SG'), $place))->toBeFalse()
        ->and(TrackingCode::matches('ACM1234567890SG', $place))->toBeFalse()
        ->and(TrackingCode::matches('place_p1', $place))->toBeTrue()
        // A tracking number may also sit directly on the subject.
        ->and(TrackingCode::matches('TN-FLAT', (object) ['uuid' => 'u', 'public_id' => '', 'tracking_number' => 'TN-FLAT']))->toBeTrue()
        ->and(TrackingCode::matches('TN-FLAT', (object) ['uuid' => 'u', 'public_id' => null]))->toBeFalse();
});

test('the tracking page path itself is never read as a tracking number', function () {
    expect(TrackingCode::parse('https://console.example/~/track-order?r=order_o1'))->toMatchArray([
        'tracking_number' => null,
        'public_id'       => 'order_o1',
    ])
        ->and(TrackingCode::parse('https://console.example/~/track-order?order=TN1&v=1'))->toMatchArray([
            'tracking_number' => 'TN1',
            'version'         => 1,
        ]);
});

test('tracking page links name the company page when its settings send links there', function () {
    app()->instance(Fleetbase\FleetOps\Support\TrackingPage\TrackingResolver::class, new class extends Fleetbase\FleetOps\Support\TrackingPage\TrackingResolver {
        public function linkSlugFor(string $companyUuid): ?string
        {
            if ($companyUuid === 'broken') {
                throw new RuntimeException('settings unavailable');
            }

            return $companyUuid === 'co-1' ? 'northwind' : null;
        }
    });

    try {
        expect(TrackingCode::pageUrl('NOR1', 'co-1'))->toEndWith('/~/t/northwind/NOR1')
            ->and(TrackingCode::pageUrl('NOR1', 'co-2'))->toEndWith('/~/track/NOR1')
            ->and(TrackingCode::pageUrl('NOR1', 'broken'))->toEndWith('/~/track/NOR1')
            ->and(TrackingCode::pageUrl('NOR1'))->toEndWith('/~/track/NOR1')
            ->and(TrackingCode::pageUrl('A B/1'))->toEndWith('/~/track/A%20B%2F1');
    } finally {
        app()->forgetInstance(Fleetbase\FleetOps\Support\TrackingPage\TrackingResolver::class);
    }
});
