<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ManifestStop.
 *
 * Represents a single physical stop within a Manifest. Each stop links to:
 *   - The parent Manifest
 *   - The Order being fulfilled at this stop
 *   - The Place (physical address) to visit
 *   - Optionally, the specific Waypoint within the order's Payload
 *
 * The sequence field holds the VROOM-optimised stop order (1-indexed).
 */
class ManifestStop extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasMetaAttributes;
    use HasApiModelBehavior;
    use TracksApiCredential;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'manifest_stops';

    /**
     * The type of public ID to generate.
     */
    protected $publicIdType = 'mstop';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'manifest_uuid',
        'order_uuid',
        'place_uuid',
        'waypoint_uuid',
        'status',
        'sequence',
        'estimated_arrival',
        'actual_arrival',
        'distance_from_prev_m',
        'duration_from_prev_s',
        'meta',
    ];

    /**
     * Attribute casts.
     */
    protected $casts = [
        'meta'                 => Json::class,
        'estimated_arrival'    => 'datetime',
        'actual_arrival'       => 'datetime',
        'sequence'             => 'integer',
        'distance_from_prev_m' => 'integer',
        'duration_from_prev_s' => 'integer',
    ];

    /**
     * Attributes appended to the model's JSON representation.
     */
    protected $appends = [
        'tracking_number',
        'address',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The parent manifest.
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class, 'manifest_uuid', 'uuid');
    }

    /**
     * The order this stop fulfils.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid')
            ->with(['trackingNumber', 'payload.dropoff']);
    }

    /**
     * The physical Place to visit.
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'place_uuid', 'uuid');
    }

    /**
     * The specific Waypoint within the order payload (nullable).
     */
    public function waypoint(): BelongsTo
    {
        return $this->belongsTo(Waypoint::class, 'waypoint_uuid', 'uuid');
    }

    // ── Computed Attributes ───────────────────────────────────────────────────

    public function getTrackingNumberAttribute(): ?string
    {
        return $this->order?->trackingNumber?->tracking_number;
    }

    public function getAddressAttribute(): ?string
    {
        return $this->place?->address ?? $this->order?->payload?->dropoff?->address;
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Mark this stop as arrived and record the actual arrival time.
     */
    public function markArrived(): self
    {
        $this->update(['status' => 'arrived', 'actual_arrival' => now()]);

        return $this;
    }

    /**
     * Mark this stop as completed and trigger manifest auto-completion check.
     */
    public function markCompleted(): self
    {
        $this->update(['status' => 'completed']);
        $this->manifest?->checkAndAutoComplete();

        return $this;
    }

    /**
     * Mark this stop as skipped.
     */
    public function markSkipped(): self
    {
        $this->update(['status' => 'skipped']);
        $this->manifest?->checkAndAutoComplete();

        return $this;
    }
}
