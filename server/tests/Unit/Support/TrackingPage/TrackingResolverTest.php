<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingPageConfig;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingResolver;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingTarget;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Database\Eloquent\Model as EloquentModel;

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
});

function trackingResolverFixture(): Illuminate\Database\SQLiteConnection
{
    $db = TrackingPageDatabase::boot();

    $rows = [
        'tracking_numbers' => [
            ['uuid' => 'tn-order', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000001US', 'owner_uuid' => 'order-1', 'owner_type' => Order::class],
            ['uuid' => 'tn-stop', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000002US', 'owner_uuid' => 'wp-1', 'owner_type' => Waypoint::class],
            ['uuid' => 'tn-item', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000003US', 'owner_uuid' => 'entity-1', 'owner_type' => Entity::class],
            ['uuid' => 'tn-loose-item', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000004US', 'owner_uuid' => 'entity-2', 'owner_type' => Entity::class],
            ['uuid' => 'tn-dropoff', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000005US', 'owner_uuid' => 'place-dropoff', 'owner_type' => Place::class],
            ['uuid' => 'tn-orphan-stop', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000006US', 'owner_uuid' => 'wp-orphan', 'owner_type' => Waypoint::class],
            ['uuid' => 'tn-orphan-item', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000007US', 'owner_uuid' => 'entity-orphan', 'owner_type' => Entity::class],
            ['uuid' => 'tn-orphan-place', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000008US', 'owner_uuid' => 'place-loose', 'owner_type' => Place::class],
            ['uuid' => 'tn-missing', 'company_uuid' => 'co-1', 'tracking_number' => 'NOR1000000009US', 'owner_uuid' => 'gone', 'owner_type' => Order::class],
            ['uuid' => 'tn-other', 'company_uuid' => 'co-2', 'tracking_number' => 'HAR2000000001US', 'owner_uuid' => 'order-2', 'owner_type' => Order::class],
        ],
        'orders' => [
            ['uuid' => 'order-1', 'public_id' => 'order_one', 'company_uuid' => 'co-1', 'payload_uuid' => 'payload-1', 'customer_uuid' => 'contact-shipper', 'customer_type' => Contact::class, 'tracking_number_uuid' => 'tn-order'],
            ['uuid' => 'order-2', 'public_id' => 'order_two', 'company_uuid' => 'co-2', 'payload_uuid' => 'payload-2', 'customer_uuid' => 'contact-other', 'customer_type' => Contact::class],
        ],
        'places' => [
            ['uuid' => 'place-dropoff', 'name' => 'Front desk'],
            ['uuid' => 'place-loose', 'name' => 'Loose'],
        ],
        'payloads' => [
            ['uuid' => 'payload-1', 'dropoff_tracking_number_uuid' => 'tn-dropoff'],
        ],
        'waypoints' => [
            ['uuid' => 'wp-1', 'public_id' => 'waypoint_one', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-a', 'customer_uuid' => 'contact-a', 'customer_type' => Contact::class],
            ['uuid' => 'wp-orphan', 'payload_uuid' => 'payload-none', 'place_uuid' => 'place-x'],
        ],
        'entities' => [
            ['uuid' => 'entity-1', 'payload_uuid' => 'payload-1', 'destination_uuid' => 'place-a'],
            ['uuid' => 'entity-2', 'payload_uuid' => 'payload-1'],
            ['uuid' => 'entity-orphan', 'payload_uuid' => 'payload-none'],
        ],
    ];

    foreach ($rows as $table => $records) {
        foreach ($records as $record) {
            TrackingPageDatabase::insert($db, $table, $record);
        }
    }

    return $db;
}

function trackingResolverSetting($db, string $key, mixed $value): void
{
    TrackingPageDatabase::insert($db, 'settings', ['key' => $key, 'value' => json_encode($value)]);
}

test('tracking numbers are normalized and anything else is refused', function () {
    expect(TrackingResolver::normalize('  NOR1000000001US '))->toBe('NOR1000000001US')
        ->and(TrackingResolver::normalize('a.b_c-1'))->toBe('a.b_c-1')
        ->and(TrackingResolver::normalize(['NOR1']))->toBeNull()
        ->and(TrackingResolver::normalize(''))->toBeNull()
        ->and(TrackingResolver::normalize('-NOR'))->toBeNull()
        ->and(TrackingResolver::normalize("NOR1' OR 1=1"))->toBeNull()
        ->and(TrackingResolver::normalize(str_repeat('A', 101)))->toBeNull()
        ->and((new TrackingResolver())->resolve('../etc'))->toBeNull();
});

test('each kind of tracking number opens its order for the right customer', function () {
    trackingResolverFixture();
    $resolver = new TrackingResolver();

    $order = $resolver->resolve('NOR1000000001US');
    expect($order)->toBeInstanceOf(TrackingTarget::class)
        ->and($order->kind)->toBe(TrackingTarget::KIND_ORDER)
        ->and($order->order->uuid)->toBe('order-1')
        ->and($order->scope->customer_uuid)->toBe('contact-shipper')
        ->and($order->companyUuid())->toBe('co-1')
        ->and($order->config['generic_page']['allowed'])->toBeTrue();

    $stop = $resolver->resolve('NOR1000000002US');
    expect($stop->kind)->toBe(TrackingTarget::KIND_STOP)
        ->and($stop->waypoint->uuid)->toBe('wp-1')
        ->and($stop->scope->customer_uuid)->toBe('contact-a');

    $item = $resolver->resolve('NOR1000000003US');
    expect($item->kind)->toBe(TrackingTarget::KIND_ITEM)
        ->and($item->waypoint->uuid)->toBe('wp-1')
        ->and($item->scope->customer_uuid)->toBe('contact-a');

    // An item with no destination stop belongs to the order's customer.
    $loose = $resolver->resolve('NOR1000000004US');
    expect($loose->waypoint)->toBeNull()
        ->and($loose->scope->customer_uuid)->toBe('contact-shipper');

    $dropoff = $resolver->resolve('NOR1000000005US');
    expect($dropoff->kind)->toBe(TrackingTarget::KIND_STOP)
        ->and($dropoff->scope->customer_uuid)->toBe('contact-shipper');

    foreach (['NOR1000000006US', 'NOR1000000007US', 'NOR1000000008US', 'NOR1000000009US', 'UNKNOWN1'] as $number) {
        expect($resolver->resolve($number))->toBeNull();
    }
});

test('companies that opted out of the shared page, or a disabled shared page, resolve to nothing', function () {
    $db       = trackingResolverFixture();
    $resolver = new TrackingResolver();

    trackingResolverSetting($db, 'company.co-1.' . TrackingPageConfig::SETTING_KEY, ['generic_page' => ['allowed' => false]]);
    expect($resolver->resolve('NOR1000000001US'))->toBeNull()
        ->and($resolver->resolve('HAR2000000001US'))->not->toBeNull();

    trackingResolverSetting($db, TrackingPageConfig::ADMIN_SETTING_KEY, ['generic_page' => ['enabled' => false]]);
    expect($resolver->resolve('HAR2000000001US'))->toBeNull()
        ->and($resolver->adminConfig()['generic_page']['enabled'])->toBeFalse();
});

test('a company page finds only its own numbers, at its own enabled slug', function () {
    $db       = trackingResolverFixture();
    $resolver = new TrackingResolver();

    expect($resolver->companyForSlug('NOT A SLUG'))->toBeNull()
        ->and($resolver->companyForSlug('northwind'))->toBeNull();

    trackingResolverSetting($db, TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind', ['company' => 'not-a-string']);
    expect($resolver->companyForSlug('northwind'))->toBeNull();

    $db->table('settings')->where('key', TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind')->update(['value' => json_encode('co-1')]);
    // Indexed, but the page is off.
    expect($resolver->companyForSlug('northwind'))->toBeNull()
        ->and($resolver->linkSlugFor('co-1'))->toBeNull();

    trackingResolverSetting($db, 'company.co-1.' . TrackingPageConfig::SETTING_KEY, ['org_page' => ['enabled' => true, 'slug' => 'northwind'], 'generic_page' => ['allowed' => false]]);
    expect($resolver->companyForSlug(' Northwind '))->toBe('co-1')
        ->and($resolver->linkSlugFor('co-1'))->toBe('northwind');

    // The company page works even with the shared page turned off, and only for its numbers.
    expect($resolver->resolve('NOR1000000001US', 'northwind')?->order->uuid)->toBe('order-1')
        ->and($resolver->resolve('HAR2000000001US', 'northwind'))->toBeNull()
        ->and($resolver->resolve('NOR1000000001US', 'harbor'))->toBeNull();

    // An index entry left behind by a slug the company changed no longer resolves.
    trackingResolverSetting($db, TrackingPageConfig::SLUG_INDEX_PREFIX . 'old-name', 'co-1');
    expect($resolver->companyForSlug('old-name'))->toBeNull();

    $db->table('settings')->where('key', 'company.co-1.' . TrackingPageConfig::SETTING_KEY)->update(['value' => json_encode(['org_page' => ['enabled' => true, 'slug' => 'northwind'], 'links' => ['target' => 'generic']])]);
    expect($resolver->linkSlugFor('co-1'))->toBeNull();
});

test('a target owned by another company is refused on a company page', function () {
    trackingResolverFixture();

    $resolver = new class extends TrackingResolver {
        public function companyForSlug(?string $slug): ?string
        {
            return 'co-2';
        }

        protected function findTrackingNumber(string $number, ?string $companyUuid): ?TrackingNumber
        {
            return parent::findTrackingNumber($number, null);
        }
    };

    expect($resolver->resolve('NOR1000000001US', 'harbor'))->toBeNull()
        ->and($resolver->targetFor(new TrackingNumber()))->toBeNull();
});
