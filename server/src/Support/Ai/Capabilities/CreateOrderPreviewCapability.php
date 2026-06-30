<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CreateOrderPreviewCapability extends AbstractFleetOpsAICapability implements AIActionCapabilityInterface
{
    public function key(): string
    {
        return 'fleet-ops.create_order';
    }

    public function label(): string
    {
        return 'Create Fleet-Ops order preview';
    }

    public function description(): string
    {
        return 'Builds and applies confirmed Fleet-Ops order creation drafts.';
    }

    public function type(): string
    {
        return 'action';
    }

    public function mode(): string
    {
        return 'confirmation_required';
    }

    public function permissions(): array
    {
        return ['fleet-ops create order'];
    }

    public function previewOnly(): bool
    {
        return false;
    }

    public function executable(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'order_config_uuid'    => 'Fleet-Ops order config UUID. Defaults to the company transport config when available.',
            'payload.pickup_uuid'  => 'Existing Fleet-Ops place UUID for pickup.',
            'payload.dropoff_uuid' => 'Existing Fleet-Ops place UUID for dropoff.',
            'payload.waypoints'    => 'Optional ordered waypoint place UUIDs.',
            'customer'             => 'Optional contact/vendor UUID.',
            'scheduled_at'         => 'Optional scheduled date/time for the order.',
            'dispatched'           => 'Optional boolean. Defaults to false for AI-created drafts.',
        ];
    }

    public function shouldPreview(AiTask $task): bool
    {
        return $this->matchesPrompt($this->prompt($task));
    }

    public function resolve(AiTask $task): array
    {
        return $this->preview($task);
    }

    public function preview(AiTask $task, array $input = []): array
    {
        $authorized  = $this->canAll($this->permissions());
        $draft       = $this->buildDraft($task, $input);
        $orderConfig = $this->resolveOrderConfig($draft);
        $pickup      = data_get($draft, 'payload.pickup') ?: data_get($draft, 'payload.pickup_query');
        $dropoff     = data_get($draft, 'payload.dropoff') ?: data_get($draft, 'payload.dropoff_query');
        $driver      = $this->resolveDriver($draft);
        $vehicle     = $this->resolveVehicle($draft);

        if ($orderConfig) {
            $draft['order_config_uuid'] = $orderConfig->uuid;
            $draft['type']              = $orderConfig->key;
        }

        if ($driver) {
            $draft['driver']               = $driver->uuid;
            $draft['driver_assigned_uuid'] = $driver->uuid;
        }

        if ($vehicle) {
            $draft['vehicle_assigned_uuid'] = $vehicle->uuid;
        }

        $missing = array_values(array_filter([
            $authorized ? null : 'permission to create Fleet-Ops orders',
            $orderConfig ? null : 'order configuration',
            $pickup ? null : 'pickup address or place',
            $dropoff ? null : 'dropoff address or place',
        ]));

        return [
            'action'          => $this->key(),
            'authorized'      => $authorized,
            'ready'           => empty($missing),
            'missing_fields'  => $missing,
            'message'         => empty($missing) ? 'Fleetbase AI prepared a Fleet-Ops order draft. Review it before applying.' : 'Fleetbase AI can create the order after these required details are resolved.',
            'apply_label'     => 'Create order',
            'cancel_label'    => 'Cancel',
            'draft'           => $draft,
            'route_preview'   => $this->routePreview($draft),
            'options'         => [
                'pod_methods' => $this->podMethods(),
            ],
            'fields'          => [
                ['label' => 'Order config', 'value' => $orderConfig?->name ?? $orderConfig?->key],
                ['label' => 'Schedule', 'value' => data_get($draft, 'scheduled_at')],
                ['label' => 'Pickup', 'value' => data_get($pickup, 'address') ?? data_get($pickup, 'name')],
                ['label' => 'Dropoff', 'value' => data_get($dropoff, 'address') ?? data_get($dropoff, 'name')],
                ['label' => 'Driver', 'value' => $driver?->name],
                ['label' => 'Vehicle', 'value' => $vehicle?->display_name ?? $vehicle?->name],
                ['label' => 'Dispatch immediately', 'value' => data_get($draft, 'dispatched') ? 'Yes' : 'No'],
            ],
            'risks'           => [
                'The order will be created only after confirmation.',
                'AI-created drafts do not dispatch by default.',
            ],
        ];
    }

    public function apply(AiTask $task, array $preview = [], array $input = []): array
    {
        if (!$this->canAll($this->permissions())) {
            throw new \RuntimeException('You do not have permission to create Fleet-Ops orders.');
        }

        if (!data_get($preview, 'ready')) {
            throw new \RuntimeException('This order draft is missing required fields and cannot be applied yet.');
        }

        $draft = array_replace_recursive((array) data_get($preview, 'draft', []), (array) data_get($input, 'draft', $input));
        Arr::forget($draft, [
            'payload.pickup_query',
            'payload.dropoff_query',
            'driver_query',
            'vehicle_query',
        ]);

        /** @var OrderController $controller */
        $controller = app(OrderController::class);
        $response   = $controller->createRecord(new Request(['order' => $draft]));

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            throw new \RuntimeException((string) $response->getContent());
        }

        $order = data_get($response, 'order');

        return [
            'action'   => $this->key(),
            'status'   => 'completed',
            'message'  => 'Fleet-Ops order was created.',
            'resource' => [
                'type'   => 'order',
                'id'     => data_get($order, 'public_id') ?? data_get($order, 'id'),
                'uuid'   => data_get($order, 'uuid'),
                'route'  => 'console.fleet-ops.operations.orders.index.details',
                'models' => array_filter([data_get($order, 'public_id') ?? data_get($order, 'uuid')]),
            ],
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return str_contains($prompt, 'order') && $this->containsAny($prompt, ['create', 'new order', 'make an order', 'book an order']);
    }

    protected function buildDraft(AiTask $task, array $input = []): array
    {
        $existing = (array) data_get($task->metadata, 'action_previews.0.draft', []);
        $draft    = array_replace_recursive($this->draftFromPrompt((string) $task->prompt), $existing, (array) data_get($input, 'draft', $input));

        $draft['dispatched'] = filter_var(data_get($draft, 'dispatched', false), FILTER_VALIDATE_BOOLEAN);
        $draft['payload']    = (array) data_get($draft, 'payload', []);
        if (blank(data_get($draft, 'scheduled_at'))) {
            unset($draft['scheduled_at']);
        }

        foreach (['pickup', 'dropoff'] as $role) {
            $query = data_get($draft, "payload.{$role}_query");
            $place = data_get($draft, "payload.{$role}");

            if (!$place && $query) {
                $place = $this->resolvePlace($query);
            }

            if ($place) {
                $draft['payload'][$role] = $place;
                if (!empty($place['uuid'])) {
                    $draft['payload']["{$role}_uuid"] = $place['uuid'];
                }
            } elseif ($query) {
                $draft['payload'][$role] = $this->provisionalPlace($query);
                unset($draft['payload']["{$role}_uuid"]);
            }
        }

        return $draft;
    }

    protected function draftFromPrompt(string $prompt): array
    {
        $orderConfig = OrderConfig::defaultOrCreate();
        $draft       = [
            'order_config_uuid' => $orderConfig?->uuid,
            'type'              => $orderConfig?->key,
            'payload'           => [],
            'dispatched'        => false,
        ];

        [$pickup, $dropoff] = $this->addressPairFromPrompt($prompt);
        if ($pickup) {
            $draft['payload']['pickup_query'] = $pickup;
            if ($place = $this->resolvePlace($pickup)) {
                $draft['payload']['pickup'] = $place;
                if (!empty($place['uuid'])) {
                    $draft['payload']['pickup_uuid'] = $place['uuid'];
                }
            }
        }

        if ($dropoff) {
            $draft['payload']['dropoff_query'] = $dropoff;
            if ($place = $this->resolvePlace($dropoff)) {
                $draft['payload']['dropoff'] = $place;
                if (!empty($place['uuid'])) {
                    $draft['payload']['dropoff_uuid'] = $place['uuid'];
                }
            }
        }

        if (str_contains(Str::lower($prompt), 'dispatch')) {
            $draft['dispatched'] = true;
        }

        if (preg_match('/(?:note|notes)[:\s]+(.+)$/i', $prompt, $matches)) {
            $draft['notes'] = trim($matches[1]);
        }

        if ($this->containsAny(Str::lower($prompt), ['proof of delivery', 'pod', 'signature', 'photo proof', 'scan proof'])) {
            $draft['pod_required'] = true;
            $draft['pod_method']   = str_contains(Str::lower($prompt), 'signature') ? 'signature' : (str_contains(Str::lower($prompt), 'photo') ? 'photo' : 'scan');
        }

        return $draft;
    }

    protected function addressPairFromPrompt(string $prompt): array
    {
        preg_match_all('/"([^"]+)"|\'([^\']+)\'/', $prompt, $quoted);
        $quotedValues = collect($quoted[1] ?? [])
            ->merge($quoted[2] ?? [])
            ->filter()
            ->values();

        if ($quotedValues->count() >= 2) {
            return [$quotedValues->get(0), $quotedValues->get(1)];
        }

        if (preg_match('/\bfrom\s+(.+?)(?=\s*,?\s+(?:to|towards)\s+)\s*,?\s+(?:to|towards)\s+(.+?)(?=\s+\b(?:with|for|on|using|require|requiring)\b|$)/i', $prompt, $matches)) {
            return [$this->cleanAddress($matches[1]), $this->cleanAddress($matches[2])];
        }

        $pickup  = $this->extractLabeledAddress($prompt, ['pickup', 'pick up'], ['dropoff', 'drop off', 'delivery']);
        $dropoff = $this->extractLabeledAddress($prompt, ['dropoff', 'drop off', 'delivery'], ['return', 'with', 'for', 'on', 'using', 'require', 'requiring']);

        if ($pickup || $dropoff) {
            return [$pickup, $dropoff];
        }

        return [null, null];
    }

    protected function extractLabeledAddress(string $prompt, array $labels, array $nextLabels = []): ?string
    {
        $labelPattern = collect($labels)
            ->map(fn ($label) => preg_quote($label, '/'))
            ->implode('|');
        $nextPattern = collect($nextLabels)
            ->map(fn ($label) => preg_quote($label, '/'))
            ->implode('|');
        $lookahead = $nextPattern ? '(?=\s*,?\s*(?:and\s+)?(?:the\s+)?(?:' . $nextPattern . ')(?:\s+(?:at|to|from))?\s+|$)' : '(?=$)';

        if (!preg_match('/\b(?:the\s+)?(?:' . $labelPattern . ')(?:\s+(?:at|to|from))?\s+(.+?)' . $lookahead . '/i', $prompt, $matches)) {
            return null;
        }

        return $this->cleanAddress($matches[1]);
    }

    protected function cleanAddress(?string $address): ?string
    {
        $address = trim((string) $address);
        $address = preg_replace('/^(?:,|\band\b|\s)+/i', '', $address);
        $address = preg_replace('/(?:,|\band\b|\s)+$/i', '', $address);
        $address = preg_replace('/\s+/', ' ', $address);

        return $address !== '' ? $address : null;
    }

    protected function resolvePlace(?string $query): ?array
    {
        $query = $this->cleanAddress($query);
        if (!$query) {
            return null;
        }

        $places = PlaceSearch::search(Place::where('company_uuid', session('company')), $query, ['geo' => true, 'limit' => 8]);
        $place  = $places->first();

        return $place instanceof Place ? $this->serializePlace($place, $query) : null;
    }

    protected function provisionalPlace(string $query): array
    {
        return [
            'id'        => null,
            'uuid'      => null,
            'public_id' => null,
            'name'      => $query,
            'address'   => $query,
            'latitude'  => null,
            'longitude' => null,
            'query'     => $query,
            'source'    => 'unresolved',
        ];
    }

    protected function serializePlace(Place $place, ?string $query = null): array
    {
        [$latitude, $longitude] = $this->placeCoordinates($place);

        return [
            'id'          => $place->public_id,
            'uuid'        => $place->uuid,
            'public_id'   => $place->public_id,
            'name'        => $place->name,
            'street1'     => $place->street1,
            'street2'     => $place->street2,
            'city'        => $place->city,
            'province'    => $place->province,
            'postal_code' => $place->postal_code,
            'country'     => $place->country,
            'address'     => $place->address ?: $place->toAddressString(['name']),
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'query'       => $query,
            'source'      => $place->exists ? 'saved' : 'geocoded',
        ];
    }

    protected function placeCoordinates(Place $place): array
    {
        $latitude  = data_get($place, 'latitude');
        $longitude = data_get($place, 'longitude');

        if ((!is_numeric($latitude) || !is_numeric($longitude)) && $place->location) {
            $point = Utils::getPointFromCoordinates($place->location);

            if ($point) {
                $latitude  = method_exists($point, 'getLat') ? $point->getLat() : data_get($point, 'coordinates.1');
                $longitude = method_exists($point, 'getLng') ? $point->getLng() : data_get($point, 'coordinates.0');
            }
        }

        return [
            is_numeric($latitude) ? (float) $latitude : null,
            is_numeric($longitude) ? (float) $longitude : null,
        ];
    }

    protected function routePreview(array $draft): array
    {
        $rawStops = [
            ['role' => 'pickup', 'place' => data_get($draft, 'payload.pickup')],
        ];
        foreach ((array) data_get($draft, 'payload.waypoints', []) as $index => $waypoint) {
            $rawStops[] = ['role' => 'waypoint', 'label' => 'Stop ' . ($index + 1), 'place' => data_get($waypoint, 'place') ?: $waypoint];
        }
        $rawStops[] = ['role' => 'dropoff', 'place' => data_get($draft, 'payload.dropoff')];

        $stops = collect($rawStops)
            ->filter(fn ($stop) => is_array($stop['place'] ?? null))
            ->map(fn ($stop) => [
                'role'        => $stop['role'],
                'label'       => $stop['label'] ?? Str::title($stop['role']),
                'address'     => data_get($stop, 'place.address') ?? data_get($stop, 'place.name'),
                'latitude'    => data_get($stop, 'place.latitude'),
                'longitude'   => data_get($stop, 'place.longitude'),
                'coordinates' => is_numeric(data_get($stop, 'place.latitude')) && is_numeric(data_get($stop, 'place.longitude')) ? [(float) data_get($stop, 'place.latitude'), (float) data_get($stop, 'place.longitude')] : null,
            ])
            ->values()
            ->all();

        return [
            'stops'       => $stops,
            'coordinates' => collect($stops)
                ->filter(fn ($stop) => is_numeric($stop['latitude']) && is_numeric($stop['longitude']))
                ->map(fn ($stop) => [$stop['latitude'], $stop['longitude']])
                ->values()
                ->all(),
        ];
    }

    protected function resolveOrderConfig(array $draft): ?OrderConfig
    {
        return OrderConfig::resolveFromIdentifier([data_get($draft, 'order_config_uuid'), data_get($draft, 'type')]) ?? OrderConfig::defaultOrCreate();
    }

    protected function resolveDriver(array $draft): ?Driver
    {
        $identifier = data_get($draft, 'driver') ?? data_get($draft, 'driver_assigned_uuid') ?? data_get($draft, 'driver_query');
        if (!$identifier) {
            return null;
        }

        return Driver::where('company_uuid', session('company'))
            ->where(function ($query) use ($identifier) {
                $query->where('uuid', $identifier)->orWhere('public_id', $identifier)->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%' . $identifier . '%'));
            })
            ->first();
    }

    protected function resolveVehicle(array $draft): ?Vehicle
    {
        $identifier = data_get($draft, 'vehicle') ?? data_get($draft, 'vehicle_assigned_uuid') ?? data_get($draft, 'vehicle_query');
        if (!$identifier) {
            return null;
        }

        return Vehicle::where('company_uuid', session('company'))
            ->where(function ($query) use ($identifier) {
                $query->where('uuid', $identifier)->orWhere('public_id', $identifier)->orWhere('plate_number', 'like', '%' . $identifier . '%')->orWhere('name', 'like', '%' . $identifier . '%');
            })
            ->first();
    }

    protected function podMethods(): array
    {
        $methods = config('fleetops.pod_methods', ['scan', 'signature', 'photo']);
        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }

        return collect((array) $methods)
            ->map(fn ($method) => trim((string) $method))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
