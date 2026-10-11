<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\FleetOps\Support\TrackingUpdate;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the public tracking page receives, per access level.
 *
 * Level 0 (a tracking number alone) carries branding, a coarse stage and how to verify.
 * Level 1 (a verified device) and level 2 (a signed-in customer) carry one customer's
 * stops within the order, built on TrackingUpdate, which already filters stops, timelines,
 * the driver and the vehicle position to the scope. Staff of the owning company see what
 * the customer sees.
 *
 * Never sent at any level: internal uuids, notes, order meta, custom fields, files other
 * than proof of delivery, rates or transactions, the sender's address, another
 * customer's stops, items or people, and the driver's surname or phone.
 */
class TrackingView
{
    public const VIEWER_VERIFIED = 'verified';
    public const VIEWER_ACCOUNT  = 'account';
    public const VIEWER_STAFF    = 'staff';

    /**
     * Status codes that show as a problem with the delivery.
     */
    public const ISSUE_CODES = ['failed', 'attempt_failed', 'delivery_failed', 'returned', 'return_to_sender'];

    /**
     * Pickup coordinates are rounded to about a kilometre: enough to draw where the route starts, not an address.
     */
    public const HUB_PRECISION  = 2;
    public const STOP_PRECISION = 5;

    /**
     * Branding as the page shows it, from the company's config with its own details as fallbacks.
     */
    public function branding(array $config, mixed $company = null, ?string $instanceLogo = null): array
    {
        $branding = (array) data_get($config, 'branding', []);
        $accent   = $branding['accent'] ?? TrackingPageConfig::DEFAULT_ACCENT;

        return [
            'name'           => $branding['display_name'] ?? data_get($company, 'name'),
            'logo_url'       => data_get($company, 'logo_uuid') ? data_get($company, 'logo_url') : $instanceLogo,
            'accent'         => $accent,
            'accent_dark'    => $branding['accent_dark'] ?? null,
            'ink'            => TrackingPageConfig::inkFor($accent),
            'support_phone'  => $branding['support_phone'] ?? data_get($company, 'phone'),
            'support_email'  => $branding['support_email'] ?? null,
            'website'        => $branding['website'] ?? data_get($company, 'website_url'),
            'powered_by'     => (bool) ($branding['powered_by'] ?? true),
            'theme'          => $branding['theme'] ?? 'system',
            'default_locale' => $branding['default_locale'] ?? 'en-us',
            'locales'        => $branding['locales'] ?? TrackingPageConfig::LOCALES,
        ];
    }

    /**
     * Level 0: what a tracking number alone shows.
     *
     * @param array $channels the masked channels a code can go out on
     */
    public function publicView(TrackingTarget $target, array $branding, array $channels, bool $signIn): array
    {
        $order  = $target->order;
        $public = (bool) data_get($target->config, 'access.public_status', true);
        $update = $target->scope ? new TrackingUpdate($order, $target->scope, $this->stopsFor($order)) : null;

        return [
            'level'           => 0,
            'tracking_number' => $target->trackingNumber->tracking_number,
            'company'         => $branding,
            'stage'           => $public ? $this->stage($order, $update) : null,
            'last_update_on'  => $public && $order->updated_at ? Carbon::parse($order->updated_at)->toDateString() : null,
            'verify'          => ['channels' => $channels],
            'sign_in'         => $signIn,
        ];
    }

    /**
     * Levels 1 and 2: one customer's view of the order.
     */
    public function fullView(TrackingTarget $target, array $branding, string $viewer, ?string $expiresAt = null): array
    {
        $order      = $target->order;
        $config     = $target->config;
        $scope      = $target->scope ?? new TrackingScope((string) $order->uuid, Order::class, (string) $order->uuid, $order);
        $stops      = $this->stopsFor($order);
        $tracker    = $this->trackerFor($order);
        $update     = $this->updateFor($order, $scope, $stops, $tracker);
        $data       = $update->toArray()['data'];
        $own        = $update->ownStops();
        $placeUuids = $own->map(fn (TrackingStop $stop) => $stop->place?->uuid)->filter()->values()->all();
        $entities   = $this->entitiesFor($order, $placeUuids, $scope);
        $showMap    = (bool) data_get($config, 'visibility.map', true);
        $location   = $showMap ? $data['location'] : null;
        $stopsView  = $this->stops($own, collect($data['stops']));

        return [
            'level'           => $viewer === self::VIEWER_ACCOUNT ? 2 : 1,
            'viewer'          => $viewer,
            'tracking_number' => $target->trackingNumber->tracking_number,
            'company'         => $branding,
            'stage'           => $this->stage($order, $update),
            'status'          => [
                'code'       => $data['order']['status'],
                'delayed'    => (bool) data_get($tracker, 'insights.is_delayed', false),
                'confidence' => data_get($tracker, 'confidence'),
            ],
            'scheduled_at'    => $this->iso($order->scheduled_at),
            'time_window'     => ['start' => $this->iso($order->time_window_start), 'end' => $this->iso($order->time_window_end)],
            'route'           => $data['route'],
            'stops'           => $stopsView,
            'focus_stop'      => $target->waypoint?->public_id,
            'eta'             => $this->eta($stopsView, $tracker, $update),
            'timeline'        => $this->timeline($data),
            'driver'          => $this->driver($data['driver'], $config),
            'location'        => $location,
            'map'             => $showMap ? $this->map($stops, $own, $tracker, $location) : null,
            'items'           => data_get($config, 'visibility.items', true) ? $this->items($entities, $viewer === self::VIEWER_ACCOUNT && data_get($config, 'visibility.item_prices', false) && $scope->isOrderCustomer($order)) : [],
            'proofs'          => $this->proofs($order, $own, $entities, $scope, $config),
            'instructions'    => [
                'text'     => $this->instructionsFor($order, $scope),
                'editable' => (bool) data_get($config, 'visibility.instructions_edit', false),
            ],
            'actions'         => [
                'report'         => (bool) data_get($config, 'visibility.report_problem', true),
                'driver_contact' => data_get($config, 'visibility.driver_contact', 'company'),
            ],
            'session'         => ['expires_at' => $expiresAt],
            'stale_after'     => $this->staleAfter($order),
        ];
    }

    /**
     * The coarse stage: preparing, scheduled, dispatched, in_transit, delivered, issue or canceled.
     */
    public function stage(Order $order, ?TrackingUpdate $update): string
    {
        $status = strtolower((string) $order->status);
        if ($status === 'canceled') {
            return 'canceled';
        }

        if (in_array($status, self::ISSUE_CODES, true)) {
            return 'issue';
        }

        $own = $update ? $update->ownStops()->filter(fn (TrackingStop $stop) => $stop->type !== 'pickup') : collect();
        if ($status === 'completed' || ($own->isNotEmpty() && $own->every(fn (TrackingStop $stop) => $stop->completed))) {
            return 'delivered';
        }

        if ($update?->isEnRoute() || $order->started) {
            return 'in_transit';
        }

        if ($order->dispatched) {
            return 'dispatched';
        }

        return $order->scheduled_at && Carbon::parse($order->scheduled_at)->isFuture() ? 'scheduled' : 'preparing';
    }

    /**
     * The customer's stops: where, when and their own status, never the pickup's place.
     */
    protected function stops(Collection $own, Collection $data): array
    {
        $byId = $data->keyBy(fn ($stop) => $stop['sequence']);

        return $own->filter(fn (TrackingStop $stop) => $stop->type !== 'pickup')->map(function (TrackingStop $stop) use ($byId) {
            $point = $stop->point();
            $entry = $byId->get($stop->sequence, []);

            return [
                'id'       => $stop->waypoint?->public_id ?? $stop->place?->public_id,
                'type'     => $stop->type,
                'sequence' => $stop->sequence,
                'status'   => $stop->status,
                'complete' => $stop->completed,
                'eta'      => $entry['eta'] ?? null,
                'timeline' => $entry['timeline'] ?? [],
                'place'    => [
                    'name'      => $stop->place?->name,
                    'address'   => $stop->place?->address,
                    'latitude'  => $point ? round($point->getLat(), self::STOP_PRECISION) : null,
                    'longitude' => $point ? round($point->getLng(), self::STOP_PRECISION) : null,
                ],
                'window'   => [
                    'start' => $this->iso($stop->waypoint?->time_window_start),
                    'end'   => $this->iso($stop->waypoint?->time_window_end),
                ],
            ];
        })->values()->all();
    }

    /**
     * The arrival estimate for the customer's next stop.
     */
    protected function eta(array $stops, array $tracker, TrackingUpdate $update): ?array
    {
        if (!$update->showsVehicle()) {
            return null;
        }

        foreach ($stops as $stop) {
            if (!$stop['complete'] && !empty($stop['eta'])) {
                return ['at' => $stop['eta']['at'], 'seconds' => $stop['eta']['seconds'], 'confidence' => data_get($tracker, 'confidence')];
            }
        }

        $at = data_get($tracker, 'eta.completion_at');

        return $at ? ['at' => $at, 'seconds' => data_get($tracker, 'eta.completion_seconds'), 'confidence' => data_get($tracker, 'confidence')] : null;
    }

    /**
     * The order's and the customer's stops' status entries, newest first.
     */
    protected function timeline(array $data): array
    {
        $entries = collect($data['order']['timeline']);
        foreach ($data['stops'] as $stop) {
            $entries = $entries->merge($stop['timeline'] ?? []);
        }

        return $entries
            ->unique(fn ($entry) => ($entry['code'] ?? '') . '|' . ($entry['created_at'] ?? '') . '|' . ($entry['status'] ?? ''))
            ->sortByDesc(fn ($entry) => $entry['created_at'] ?? '')
            ->values()
            ->all();
    }

    protected function driver(?array $driver, array $config): ?array
    {
        if (!$driver) {
            return null;
        }

        return [
            'name'    => data_get($config, 'visibility.driver_name', true) ? $driver['name'] : null,
            'vehicle' => data_get($config, 'visibility.vehicle', true) ? $driver['vehicle'] : null,
            'online'  => $driver['online'],
        ];
    }

    /**
     * Where the route starts (to about a kilometre) and, when every stop is this
     * customer's, the route line. On an order with other customers the line would trace
     * their stops, so it is left out.
     */
    protected function map(Collection $stops, Collection $own, array $tracker, ?array $location): array
    {
        $pickup = $stops->first(fn (TrackingStop $stop) => $stop->type === 'pickup');
        $point  = $pickup?->point();
        $mine   = $stops->every(fn (TrackingStop $stop) => $stop->type === 'pickup' || $own->contains(fn (TrackingStop $owned) => $owned === $stop));

        return [
            'hub'      => $point ? ['latitude' => round($point->getLat(), self::HUB_PRECISION), 'longitude' => round($point->getLng(), self::HUB_PRECISION)] : null,
            'polyline' => $mine && $location ? data_get($tracker, 'route.polyline') : null,
        ];
    }

    /**
     * The customer's items: those going to their stops, and on an order with no stop
     * customers, the order customer's.
     */
    protected function items(Collection $entities, bool $prices): array
    {
        return $entities->map(fn (Entity $entity) => array_filter([
            'id'          => $entity->public_id,
            'name'        => $entity->name,
            'description' => $entity->description,
            'quantity'    => data_get($entity, 'meta.quantity'),
            'photo_url'   => $entity->photo_uuid ? $entity->photo_url : null,
            'tracking'    => data_get($entity, 'tracking'),
            'price'       => $prices ? $entity->price : null,
            'currency'    => $prices ? $entity->currency : null,
        ], fn ($value) => $value !== null))->values()->all();
    }

    /**
     * Proof of delivery for the customer's completed stops and items.
     */
    protected function proofs(Order $order, Collection $own, Collection $entities, TrackingScope $scope, array $config): array
    {
        $subjects = $own->filter(fn (TrackingStop $stop) => $stop->completed)->map(fn (TrackingStop $stop) => $stop->waypoint?->uuid)->filter()->values()->all();
        $subjects = array_merge($subjects, $entities->map(fn (Entity $entity) => $entity->uuid)->all());
        if ($scope->isOrderCustomer($order) && strtolower((string) $order->status) === 'completed') {
            $subjects[] = $order->uuid;
        }

        return $this->proofsFor($subjects)
            ->map(fn (Proof $proof) => ['id' => $proof->public_id, 'type' => static::proofType($proof), 'created_at' => $this->iso($proof->created_at)])
            ->filter(fn ($proof) => ($proof['type'] !== 'photo' || data_get($config, 'visibility.pod_photo', true)) && ($proof['type'] !== 'signature' || data_get($config, 'visibility.pod_signature', true)))
            ->values()
            ->all();
    }

    /**
     * signature, photo or scan.
     */
    public static function proofType(Proof $proof): string
    {
        $fileType = strtolower((string) data_get($proof, 'file.type'));
        if ($fileType === 'signature' || (!empty($proof->raw_data) && $fileType === '')) {
            return 'signature';
        }

        return $fileType === 'photo' || $proof->file_uuid ? 'photo' : 'scan';
    }

    /**
     * Proofs the scope may open, by public id.
     */
    public function proofFor(TrackingTarget $target, string $publicId): ?Proof
    {
        $view = $this->fullView($target, [], self::VIEWER_VERIFIED);

        if (!collect($view['proofs'])->contains(fn ($proof) => $proof['id'] === $publicId)) {
            return null;
        }

        return $this->findProof($publicId);
    }

    protected function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    protected function stopsFor(Order $order): Collection
    {
        return app(TrackingContextBuilder::class)->build($order, TrackingOptions::fromArray([], $order->company_uuid))->stops;
    }

    protected function trackerFor(Order $order): array
    {
        if (in_array(strtolower((string) $order->status), TrackingUpdate::TERMINAL_STATUSES, true)) {
            return [];
        }

        try {
            return app(TrackingIntelligenceService::class)->track($order);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function updateFor(Order $order, TrackingScope $scope, Collection $stops, array $tracker): TrackingUpdate
    {
        return TrackingUpdate::for($order, $scope, $stops, $tracker);
    }

    protected function staleAfter(Order $order): int
    {
        return TrackingOptions::fromArray([], $order->company_uuid)->staleLocationThresholdSeconds;
    }

    /**
     * @param array<int, string> $placeUuids the customer's stop places
     */
    protected function entitiesFor(Order $order, array $placeUuids, TrackingScope $scope): Collection
    {
        if (!$order->payload_uuid) {
            return collect();
        }

        $orderCustomer = $scope->isOrderCustomer($order);

        return Entity::withoutGlobalScopes()
            ->where('payload_uuid', $order->payload_uuid)
            ->get()
            ->filter(fn (Entity $entity) => $entity->destination_uuid ? in_array($entity->destination_uuid, $placeUuids, true) : $orderCustomer)
            ->values();
    }

    protected function proofsFor(array $subjectUuids): Collection
    {
        return $subjectUuids === [] ? collect() : Proof::withoutGlobalScopes()->with('file')->whereIn('subject_uuid', $subjectUuids)->orderBy('created_at')->get();
    }

    protected function findProof(string $publicId): ?Proof
    {
        return Proof::withoutGlobalScopes()->with('file')->where('public_id', $publicId)->first();
    }

    protected function instructionsFor(Order $order, TrackingScope $scope): ?string
    {
        $instructions = data_get($order->getMeta('recipient_instructions', []), sha1($scope->key()));

        return is_string($instructions) && $instructions !== '' ? $instructions : null;
    }
}
