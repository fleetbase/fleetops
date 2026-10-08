<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingPageConfig;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingTarget;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingView;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrackingViewPlace extends Place
{
    protected $casts   = [];
    protected $appends = [];

    public function getAddressAttribute()
    {
        return $this->attributes['street1'] ?? null;
    }
}

class TrackingViewDriver extends Driver
{
    protected $casts   = [];
    protected $appends = [];
}

/**
 * A TrackingView whose stops, tracker, items, proofs and instructions come from the test.
 */
class TrackingViewProbe extends TrackingView
{
    public Collection $stops;
    public array $tracker       = [];
    public ?Collection $entities = null;
    public ?Collection $proofRows = null;
    public array $proofQueries  = [];

    protected function stopsFor(Order $order): Collection
    {
        return $this->stops;
    }

    protected function trackerFor(Order $order): array
    {
        return $this->tracker;
    }

    protected function entitiesFor(Order $order, array $placeUuids, TrackingScope $scope): Collection
    {
        return $this->entities ?? parent::entitiesFor($order, $placeUuids, $scope);
    }

    protected function proofsFor(array $subjectUuids): Collection
    {
        $this->proofQueries[] = $subjectUuids;

        return $this->proofRows ?? parent::proofsFor($subjectUuids);
    }
}

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::unsetConnectionResolver();
});

function trackingViewPlace(string $uuid, string $name, float $lat, float $lng): TrackingViewPlace
{
    $place = new TrackingViewPlace();
    $place->setRawAttributes(['uuid' => $uuid, 'public_id' => 'place_' . $uuid, 'name' => $name, 'street1' => $name . ' street', 'location' => new Point($lat, $lng)], true);

    return $place;
}

function trackingViewWaypoint(string $uuid, ?string $customer, array $extra = []): Waypoint
{
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(array_merge(['uuid' => $uuid, 'public_id' => 'waypoint_' . $uuid, 'customer_uuid' => $customer, 'customer_type' => $customer ? Contact::class : null], $extra), true);

    return $waypoint;
}

/**
 * An order with a pickup and two stops: stop A for contact-a, stop B for contact-b.
 */
function trackingViewOrder(array $attributes = []): Order
{
    $order = new Order();
    $order->setRawAttributes(array_merge([
        'uuid'                 => 'order-1',
        'company_uuid'         => 'co-1',
        'payload_uuid'         => 'payload-1',
        'customer_uuid'        => 'contact-shipper',
        'customer_type'        => Contact::class,
        'tracking_number_uuid' => 'tn-order',
        'status'               => 'started',
        'started'              => true,
        'dispatched'           => true,
        'notes'                => 'Gate code 1234',
        'meta'                 => json_encode(['secret' => 'internal']),
        'updated_at'           => '2026-10-08 08:30:00',
    ], $attributes), true);

    $driver = new TrackingViewDriver();
    $driver->setRawAttributes(['uuid' => 'driver-1', 'name' => 'Daniel Okafor', 'phone' => '+15550001111', 'online' => 1, 'heading' => 92, 'location' => new Point(1.30123456, 103.80123456), 'updated_at' => '2026-10-08 08:59:00'], true);
    $order->setRelation('driverAssigned', $driver);
    $order->setRelation('vehicleAssigned', null);

    return $order;
}

function trackingViewStops(bool $aComplete = false): Collection
{
    return collect([
        new TrackingStop('place-pickup', 'place_pickup', 'pickup', 'completed', trackingViewPlace('pickup', 'Sender Warehouse', 1.2876, 103.8456), null, true, 0, null),
        new TrackingStop('place-a', 'place_a', 'waypoint', $aComplete ? 'completed' : 'pending', trackingViewPlace('place-a', 'Maya Flat', 1.3333333, 103.7777777), trackingViewWaypoint('wp-a', 'contact-a', ['time_window_start' => '2026-10-08 14:10:00', 'time_window_end' => '2026-10-08 14:40:00']), $aComplete, 1, 'tn-a'),
        new TrackingStop('place-b', 'place_b', 'waypoint', 'pending', trackingViewPlace('place-b', 'Other Customer House', 1.4, 103.9), trackingViewWaypoint('wp-b', 'contact-b'), false, 2, 'tn-b'),
    ]);
}

function trackingViewTarget(Order $order, ?TrackingScope $scope, array $config = [], ?Waypoint $waypoint = null): TrackingTarget
{
    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-a', 'tracking_number' => 'NOR1000000002US'], true);

    return new TrackingTarget($order, $scope, $trackingNumber, TrackingTarget::KIND_STOP, $waypoint, TrackingPageConfig::sanitize($config));
}

function trackingViewTracker(): array
{
    return [
        'confidence' => 'high',
        'insights'   => ['is_delayed' => true],
        'route'      => [
            'polyline' => '_p~iF~ps|U_ulLnnqC',
            'legs'     => [
                ['stop' => ['sequence' => 1], 'eta_seconds' => 1500, 'eta_at' => '2026-10-08T14:24:00.000000Z'],
                ['stop' => ['sequence' => 2], 'eta_seconds' => 2400, 'eta_at' => '2026-10-08T14:40:00.000000Z'],
            ],
        ],
        'eta'        => ['completion_at' => '2026-10-08T15:00:00.000000Z', 'completion_seconds' => 3600],
    ];
}

function trackingViewEntity(string $uuid, ?string $destination, array $extra = []): Entity
{
    $entity = new Entity();
    $entity->setRawAttributes(array_merge(['uuid' => $uuid, 'public_id' => 'entity_' . $uuid, 'name' => 'Item ' . $uuid, 'description' => 'Boxed', 'destination_uuid' => $destination, 'price' => '1500', 'currency' => 'SGD', 'meta' => json_encode(['quantity' => 2, 'secret' => 'x'])], $extra), true);
    $entity->setRelation('trackingNumber', null);

    return $entity;
}

test('branding falls back to the company, then the instance', function () {
    $view    = new TrackingView();
    $company = (object) ['name' => 'Northwind Couriers', 'logo_uuid' => 'logo-1', 'logo_url' => 'https://cdn.example/logo.png', 'phone' => '+15105550142', 'website_url' => 'https://northwind.example'];

    $branding = $view->branding(TrackingPageConfig::sanitize(['branding' => ['accent' => '#5CC9BE', 'support_email' => 'help@northwind.example']]), $company);
    expect($branding)->toMatchArray([
        'name'          => 'Northwind Couriers',
        'logo_url'      => 'https://cdn.example/logo.png',
        'accent'        => '#5CC9BE',
        'ink'           => '#14191A',
        'support_phone' => '+15105550142',
        'support_email' => 'help@northwind.example',
        'website'       => 'https://northwind.example',
        'powered_by'    => true,
    ]);

    $instance = $view->branding(['branding' => ['display_name' => 'Fleetbase']], null, 'https://instance.example/logo.png');
    expect($instance['name'])->toBe('Fleetbase')
        ->and($instance['logo_url'])->toBe('https://instance.example/logo.png')
        ->and($instance['accent'])->toBe(TrackingPageConfig::DEFAULT_ACCENT)
        ->and($instance['locales'])->toBe(TrackingPageConfig::LOCALES);
});

test('the coarse stage follows the order and the customer stops', function () {
    $view   = new TrackingViewProbe();
    $scopeA = new TrackingScope('order-1', Contact::class, 'contact-a');
    $update = fn (Order $order, bool $complete = false) => new Fleetbase\FleetOps\Support\TrackingUpdate($order, $scopeA, trackingViewStops($complete));

    expect($view->stage(trackingViewOrder(['status' => 'canceled']), null))->toBe('canceled')
        ->and($view->stage(trackingViewOrder(['status' => 'attempt_failed']), null))->toBe('issue')
        ->and($view->stage(trackingViewOrder(['status' => 'completed']), null))->toBe('delivered')
        ->and($view->stage(trackingViewOrder(), $update(trackingViewOrder(), true)))->toBe('delivered')
        ->and($view->stage(trackingViewOrder(), $update(trackingViewOrder())))->toBe('in_transit')
        ->and($view->stage(trackingViewOrder(['started' => false, 'status' => 'dispatched']), null))->toBe('dispatched')
        ->and($view->stage(trackingViewOrder(['started' => false, 'dispatched' => false, 'status' => 'created', 'scheduled_at' => '2099-01-01 09:00:00']), null))->toBe('scheduled')
        ->and($view->stage(trackingViewOrder(['started' => false, 'dispatched' => false, 'status' => 'created']), null))->toBe('preparing');
});

test('a tracking number alone shows the stage, the last update and how to verify', function () {
    $view        = new TrackingViewProbe();
    $view->stops = trackingViewStops();
    $scopeA      = new TrackingScope('order-1', Contact::class, 'contact-a');

    $public = $view->publicView(trackingViewTarget(trackingViewOrder(), $scopeA), ['name' => 'Northwind'], [['type' => 'sms', 'masked' => '•••• •• 4821']], true);
    expect($public)->toBe([
        'level'           => 0,
        'tracking_number' => 'NOR1000000002US',
        'company'         => ['name' => 'Northwind'],
        'stage'           => 'in_transit',
        'last_update_on'  => '2026-10-08',
        'verify'          => ['channels' => [['type' => 'sms', 'masked' => '•••• •• 4821']]],
        'sign_in'         => true,
    ]);

    $hidden = $view->publicView(trackingViewTarget(trackingViewOrder(), null, ['access' => ['public_status' => false]]), [], [], false);
    expect($hidden['stage'])->toBeNull()->and($hidden['last_update_on'])->toBeNull();
});

test('a verified customer sees only their own stop on an order with several customers', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    TrackingPageDatabase::boot();

    $view           = new TrackingViewProbe();
    $view->stops    = trackingViewStops();
    $view->tracker  = trackingViewTracker();
    $view->entities = collect([trackingViewEntity('a1', 'place-a')]);
    $order          = trackingViewOrder(['meta' => json_encode(['recipient_instructions' => [sha1('order-1:' . Contact::class . ':contact-a') => 'Leave with concierge']])]);
    $scopeA         = new TrackingScope('order-1', Contact::class, 'contact-a', $order);

    $data = $view->fullView(trackingViewTarget($order, $scopeA, ['visibility' => ['instructions_edit' => true]], $view->stops[1]->waypoint), ['name' => 'Northwind'], TrackingView::VIEWER_VERIFIED, '2026-10-09T09:00:00.000000Z');

    expect($data['level'])->toBe(1)
        ->and($data['viewer'])->toBe('verified')
        ->and($data['stage'])->toBe('in_transit')
        ->and($data['status'])->toBe(['code' => 'started', 'delayed' => true, 'confidence' => 'high'])
        ->and($data['focus_stop'])->toBe('waypoint_wp-a')
        ->and($data['route'])->toBe(['total_stops' => 3, 'completed_stops' => 1, 'stops_before' => 0])
        ->and($data['stops'])->toHaveCount(1)
        ->and($data['stops'][0])->toMatchArray([
            'id'       => 'waypoint_wp-a',
            'sequence' => 1,
            'complete' => false,
            'place'    => ['name' => 'Maya Flat', 'address' => 'Maya Flat street', 'latitude' => 1.33333, 'longitude' => 103.77778],
        ])
        ->and($data['stops'][0]['eta']['at'])->toBe('2026-10-08T14:24:00.000000Z')
        ->and($data['stops'][0]['window']['start'])->toBe('2026-10-08T14:10:00.000000Z')
        ->and($data['eta'])->toBe(['at' => '2026-10-08T14:24:00.000000Z', 'seconds' => 1500, 'confidence' => 'high'])
        ->and($data['driver'])->toBe(['name' => 'Daniel', 'vehicle' => null, 'online' => true])
        ->and($data['location']['latitude'])->toBe(1.3012)
        ->and($data['map'])->toBe(['hub' => ['latitude' => 1.29, 'longitude' => 103.85], 'polyline' => null])
        ->and($data['items'])->toBe([['id' => 'entity_a1', 'name' => 'Item a1', 'description' => 'Boxed', 'quantity' => 2]])
        ->and($data['instructions'])->toBe(['text' => 'Leave with concierge', 'editable' => true])
        ->and($data['actions'])->toBe(['report' => true, 'driver_contact' => 'company'])
        ->and($data['session'])->toBe(['expires_at' => '2026-10-09T09:00:00.000000Z'])
        ->and($data['stale_after'])->toBe(300)
        ->and($data['proofs'])->toBe([]);

    // Nothing of the other customer, the sender, the driver or the order internals.
    $json = json_encode($data);
    foreach (['Other Customer House', 'waypoint_wp-b', 'Sender Warehouse', 'Okafor', '+15550001111', 'Gate code', 'internal', 'order-1', 'contact-a', 'co-1', 'SGD'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

test('the route line, prices and proofs appear only when the customer may see them', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    TrackingPageDatabase::boot();

    // Every stop belongs to the order customer: the route line is theirs to see.
    $order       = trackingViewOrder(['status' => 'completed']);
    $scope       = new TrackingScope('order-1', Contact::class, 'contact-shipper', $order);
    $view        = new TrackingViewProbe();
    $view->stops = collect([
        new TrackingStop('place-pickup', 'place_pickup', 'pickup', 'completed', trackingViewPlace('pickup', 'Sender', 1.28, 103.84), null, true, 0, null),
        new TrackingStop('place-d', 'place_d', 'dropoff', 'completed', trackingViewPlace('place-d', 'Home', 1.35, 103.85), null, true, 1, null),
    ]);
    $view->tracker   = trackingViewTracker();
    $view->entities  = collect([trackingViewEntity('d1', null, ['photo_uuid' => 'photo-1'])]);
    $signature       = new Proof();
    $signature->setRawAttributes(['public_id' => 'proof_sig', 'raw_data' => 'iVBOR', 'created_at' => '2026-10-08 08:50:00'], true);
    $photo           = new Proof();
    $photo->setRawAttributes(['public_id' => 'proof_photo', 'file_uuid' => 'file-1', 'created_at' => '2026-10-08 08:51:00'], true);
    $scan            = new Proof();
    $scan->setRawAttributes(['public_id' => 'proof_scan', 'remarks' => 'Verified by QR Code Scan'], true);
    $view->proofRows = collect([$signature, $photo, $scan]);

    $data = $view->fullView(trackingViewTarget($order, $scope, ['visibility' => ['item_prices' => true, 'pod_photo' => false]]), [], TrackingView::VIEWER_ACCOUNT);

    expect($data['level'])->toBe(2)
        ->and($data['stage'])->toBe('delivered')
        ->and($data['driver'])->toBeNull()
        ->and($data['location'])->toBeNull()
        ->and($data['eta'])->toBeNull()
        ->and($data['map']['polyline'])->toBeNull()
        ->and($data['items'][0])->toMatchArray(['price' => '1500', 'currency' => 'SGD'])
        ->and($data['items'][0]['photo_url'])->toBeString()
        ->and(collect($data['proofs'])->pluck('type')->all())->toBe(['signature', 'scan'])
        ->and($view->proofQueries[0])->toBe(['d1', 'order-1']);

    // While under way, with nothing but this customer's stops, the line is drawn.
    $order       = trackingViewOrder();
    $view->stops = collect([
        new TrackingStop('place-pickup', 'place_pickup', 'pickup', 'completed', trackingViewPlace('pickup', 'Sender', 1.28, 103.84), null, true, 0, null),
        new TrackingStop('place-d', 'place_d', 'dropoff', 'pending', trackingViewPlace('place-d', 'Home', 1.35, 103.85), null, false, 1, null),
    ]);
    $view->tracker = ['eta' => ['completion_at' => '2026-10-08T10:00:00.000000Z', 'completion_seconds' => 3600], 'route' => ['polyline' => 'abc']];
    $data          = $view->fullView(trackingViewTarget($order, new TrackingScope('order-1', Contact::class, 'contact-shipper', $order)), [], TrackingView::VIEWER_STAFF);

    expect($data['map']['polyline'])->toBe('abc')
        ->and($data['eta'])->toBe(['at' => '2026-10-08T10:00:00.000000Z', 'seconds' => 3600, 'confidence' => null])
        ->and($data['items'][0])->not->toHaveKey('price');

    // The company can hide the map, the driver's name, the vehicle and the items.
    $view->tracker = [];
    $hidden        = $view->fullView(trackingViewTarget($order, new TrackingScope('order-1', Contact::class, 'contact-shipper', $order), ['visibility' => ['map' => false, 'driver_name' => false, 'vehicle' => false, 'items' => false]]), [], TrackingView::VIEWER_VERIFIED);
    expect($hidden['map'])->toBeNull()
        ->and($hidden['location'])->toBeNull()
        ->and($hidden['driver'])->toBe(['name' => null, 'vehicle' => null, 'online' => true])
        ->and($hidden['items'])->toBe([])
        ->and($hidden['eta'])->toBeNull();

    // An order that names no customer still renders for staff, with no stops of anyone's.
    $none = $view->fullView(trackingViewTarget(trackingViewOrder(['customer_uuid' => null, 'customer_type' => null]), null), [], TrackingView::VIEWER_STAFF);
    expect($none['stops'])->toBe([])->and($none['instructions']['text'])->toBeNull();
});

test('proof types and proofs a scope may open', function () {
    TrackingPageDatabase::boot();
    $file = new Fleetbase\Models\File();
    $file->setRawAttributes(['type' => 'signature'], true);
    $signed = new Proof();
    $signed->setRelation('file', $file);

    $view        = new class extends TrackingViewProbe {
        public ?Proof $found = null;

        protected function findProof(string $publicId): ?Proof
        {
            return $this->found;
        }
    };
    $view->stops = trackingViewStops(true);
    $view->entities = collect();
    $proof = new Proof();
    $proof->setRawAttributes(['public_id' => 'proof_a', 'file_uuid' => 'file-a'], true);
    $view->proofRows = collect([$proof]);
    $view->found     = $proof;

    $target = trackingViewTarget(trackingViewOrder(), new TrackingScope('order-1', Contact::class, 'contact-a'));

    expect(TrackingView::proofType($signed))->toBe('signature')
        ->and($view->proofFor($target, 'proof_a'))->toBe($proof)
        ->and($view->proofFor($target, 'proof_other'))->toBeNull()
        ->and($view->proofQueries[0])->toBe(['wp-a']);
});

test('stops, the tracker, items and proofs come from their real sources', function () {
    $db = TrackingPageDatabase::boot();
    TrackingPageDatabase::insert($db, 'entities', ['uuid' => 'e-a', 'payload_uuid' => 'payload-1', 'destination_uuid' => 'place-a', 'name' => 'Lamp']);
    TrackingPageDatabase::insert($db, 'entities', ['uuid' => 'e-none', 'payload_uuid' => 'payload-1', 'name' => 'Shade']);
    TrackingPageDatabase::insert($db, 'entities', ['uuid' => 'e-b', 'payload_uuid' => 'payload-1', 'destination_uuid' => 'place-b', 'name' => 'Chair']);
    TrackingPageDatabase::insert($db, 'proofs', ['uuid' => 'p-1', 'public_id' => 'proof_one', 'subject_uuid' => 'wp-a', 'created_at' => '2026-10-08 09:00:00']);

    $context = new Fleetbase\FleetOps\Tracking\TrackingContext(trackingViewOrder(), null, null, null, null, collect(['stop']), collect(), collect(), null, null, null);
    app()->instance(TrackingContextBuilder::class, new class($context) extends TrackingContextBuilder {
        public function __construct(private $context)
        {
        }

        public function build(Order $order, Fleetbase\FleetOps\Tracking\TrackingOptions $options): Fleetbase\FleetOps\Tracking\TrackingContext
        {
            return $this->context;
        }
    });
    app()->instance(TrackingIntelligenceService::class, new class extends TrackingIntelligenceService {
        public bool $fail = false;

        public function __construct()
        {
        }

        public function track(Order $order, array|Fleetbase\FleetOps\Tracking\TrackingOptions $options = []): array
        {
            if ($this->fail) {
                throw new RuntimeException('provider down');
            }

            return ['provider' => 'fake'];
        }
    });

    $view    = new TrackingView();
    $call    = fn (string $method, ...$args) => (fn () => $this->{$method}(...$args))->call($view);
    $order   = trackingViewOrder();
    $scopeA  = new TrackingScope('order-1', Contact::class, 'contact-a', $order);
    $shipper = new TrackingScope('order-1', Contact::class, 'contact-shipper', $order);

    expect($call('stopsFor', $order)->all())->toBe(['stop'])
        ->and($call('trackerFor', $order))->toBe(['provider' => 'fake'])
        ->and($call('trackerFor', trackingViewOrder(['status' => 'completed'])))->toBe([]);

    app(TrackingIntelligenceService::class)->fail = true;
    expect($call('trackerFor', $order))->toBe([])
        ->and($call('entitiesFor', $order, ['place-a'], $scopeA)->pluck('uuid')->all())->toBe(['e-a'])
        ->and($call('entitiesFor', $order, [], $shipper)->pluck('uuid')->all())->toBe(['e-none'])
        ->and($call('entitiesFor', trackingViewOrder(['payload_uuid' => null]), [], $shipper)->all())->toBe([])
        ->and($call('proofsFor', ['wp-a'])->pluck('public_id')->all())->toBe(['proof_one'])
        ->and($call('proofsFor', [])->all())->toBe([])
        ->and($call('findProof', 'proof_one')?->uuid)->toBe('p-1')
        ->and($call('updateFor', $order, $scopeA, trackingViewStops(), [])->ownStops()->count())->toBe(1);
});
