<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Trailer extends Asset
{
    public const ASSET_CLASS = 'trailer';

    /**
     * Lifecycle statuses. Attachment and connectivity are derived separately.
     */
    public const STATUSES = ['available', 'in_use', 'maintenance', 'out_of_service', 'retired'];

    /**
     * Stable trailer classification values shared by validation, import and the console.
     */
    public const TYPES = ['dry_van', 'reefer', 'flatbed', 'step_deck', 'lowboy', 'tanker', 'bulk', 'dump', 'chassis', 'curtain_side', 'car_carrier', 'livestock', 'logging', 'dolly', 'specialty', 'other'];

    /**
     * Ownership arrangements accepted for a trailer.
     */
    public const OWNERSHIP_TYPES = ['owned', 'leased', 'financed', 'rented'];

    /**
     * Keep the API payload identity independent from the shared assets table.
     */
    protected string $payloadKey = 'trailer';

    protected $publicIdType = 'trailer';

    protected $appends = ['category_name', 'vendor_name', 'warranty_name', 'current_location', 'photo_url', 'display_name', 'is_online', 'last_maintenance', 'next_maintenance_due', 'attachment_state', 'connectivity_status'];

    protected $fillable = [
        'company_uuid', 'category_uuid', 'vendor_uuid', 'warranty_uuid', 'telematic_uuid', 'current_place_uuid', 'photo_uuid',
        'name', 'description', 'code', 'type', 'body_type', 'location', 'speed', 'heading', 'altitude', 'status', 'online', 'last_online_at',
        'usage_type', 'vin', 'plate_number', 'make', 'model', 'year', 'color', 'serial_number', 'measurement_system', 'odometer', 'odometer_unit',
        'ownership_type', 'engine_hours', 'length', 'width', 'height', 'tare_weight', 'gvwr', 'payload_capacity', 'cargo_volume', 'axle_count',
        'tire_count', 'door_count', 'coupling_type', 'brake_type', 'abs_equipped', 'ebs_equipped', 'refrigerated', 'temperature_min',
        'temperature_max', 'reefer_engine_hours', 'acquisition_cost', 'current_value', 'depreciation_rate', 'insurance_value', 'currency',
        'financing_status', 'purchased_at', 'lease_expires_at', 'capacity', 'specs', 'attributes', 'telematics', 'notes', 'slug',
    ];

    protected $casts = [
        'year'           => 'integer', 'odometer' => 'integer', 'engine_hours' => 'integer', 'axle_count' => 'integer', 'tire_count' => 'integer',
        'door_count'     => 'integer', 'online' => 'boolean', 'abs_equipped' => 'boolean', 'ebs_equipped' => 'boolean', 'refrigerated' => 'boolean',
        'last_online_at' => 'datetime', 'purchased_at' => 'date', 'lease_expires_at' => 'date', 'location' => \Fleetbase\FleetOps\Casts\Point::class,
        'capacity'       => \Fleetbase\Casts\Json::class, 'specs' => \Fleetbase\Casts\Json::class, 'attributes' => \Fleetbase\Casts\Json::class,
        'telematics'     => \Fleetbase\Casts\Json::class,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('trailer', fn (Builder $query) => $query->where($query->qualifyColumn('asset_class'), static::ASSET_CLASS));
        static::creating(function (Trailer $trailer) {
            $trailer->asset_class = static::ASSET_CLASS;
            $trailer->status ??= 'available';
        });
        static::saving(fn (Trailer $trailer) => $trailer->asset_class = static::ASSET_CLASS);
    }

    /**
     * Bulk removal never deletes a trailer that is still coupled to a vehicle: the
     * active towing connection would be orphaned. Attached trailers are skipped, and
     * the operation is refused outright when every selected trailer is attached.
     */
    public function bulkRemove($ids = [])
    {
        $ids      = array_values(array_filter(array_map('strval', (array) $ids), 'strlen'));
        $selected = static::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->whereIn('uuid', $ids)->orWhereIn('public_id', $ids))
            ->get(['uuid', 'public_id']);
        $attachedUuids = AssetConnection::where('company_uuid', session('company'))
            ->whereIn('active_connected_uuid', $selected->pluck('uuid'))
            ->pluck('active_connected_uuid')
            ->all();
        $blocked = $selected
            ->filter(fn (Trailer $trailer) => in_array($trailer->uuid, $attachedUuids, true))
            ->flatMap(fn (Trailer $trailer) => [$trailer->uuid, $trailer->public_id])
            ->all();
        $deletable = array_values(array_filter($ids, fn ($id) => !in_array($id, $blocked, true)));

        if (empty($deletable)) {
            throw new \RuntimeException('Detach the selected trailers from their vehicles before deleting them.');
        }

        return parent::bulkRemove($deletable);
    }

    public function currentConnection(): HasOne
    {
        return $this->hasOne(AssetConnection::class, 'connected_uuid', 'uuid')
            ->where('connected_type', static::class)->whereNotNull('active_connected_uuid')->whereNull('disconnected_at');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'attachable_uuid', 'uuid')->where('attachable_type', static::class);
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class, 'equipable_uuid', 'uuid')->where('equipable_type', static::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'subject_uuid', 'uuid')->where('subject_type', static::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'maintainable_uuid', 'uuid')->where('maintainable_type', static::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class, 'subject_uuid', 'uuid')->where('subject_type', static::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'target_uuid', 'uuid')->where('target_type', static::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(AssetConnection::class, 'connected_uuid', 'uuid')->where('connected_type', static::class)->latest('connected_at');
    }

    public function createPosition(array $attributes = [], Model|string|null $destination = null): ?Position
    {
        $latitude  = $attributes['latitude'] ?? null;
        $longitude = $attributes['longitude'] ?? null;
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return Position::create([
            'company_uuid'     => $this->company_uuid,
            'subject_uuid'     => $this->uuid,
            'subject_type'     => static::class,
            'destination_uuid' => $destination instanceof Model ? $destination->uuid : $destination,
            'coordinates'      => new Point((float) $latitude, (float) $longitude),
            'heading'          => $attributes['heading'] ?? null,
            'bearing'          => $attributes['bearing'] ?? null,
            'speed'            => $attributes['speed'] ?? null,
            'altitude'         => $attributes['altitude'] ?? null,
        ]);
    }

    public function getCurrentVehicleAttribute(): ?Vehicle
    {
        return $this->currentConnection?->vehicle;
    }

    public function getAttachmentStateAttribute(): string
    {
        return $this->currentConnection ? 'attached' : 'detached';
    }

    public function getConnectivityStatusAttribute(): string
    {
        if (!$this->last_online_at) {
            return 'never_connected';
        }
        if ($this->last_online_at->gte(now()->subMinutes(10))) {
            return 'online';
        }
        if ($this->last_online_at->gte(now()->subDay())) {
            return 'recently_offline';
        }

        return 'offline';
    }
}
