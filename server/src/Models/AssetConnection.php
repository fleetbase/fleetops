<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetConnection extends Model
{
    use HasUuid;
    use HasPublicId;
    use SoftDeletes;

    protected $publicIdType = 'connection';
    protected $fillable     = ['company_uuid', 'connector_type', 'connector_uuid', 'connected_type', 'connected_uuid', 'active_connected_uuid', 'active_connector_position', 'relationship_type', 'position', 'connected_at', 'disconnected_at', 'source', 'confidence', 'notes', 'meta', 'created_by_uuid', 'updated_by_uuid'];
    protected $casts        = ['connector_type' => PolymorphicType::class, 'connected_type' => PolymorphicType::class, 'connected_at' => 'datetime', 'disconnected_at' => 'datetime', 'meta' => Json::class];

    public function connector(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'connector_type', 'connector_uuid');
    }

    public function connected(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'connected_type', 'connected_uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'connector_uuid', 'uuid');
    }

    public function trailer(): BelongsTo
    {
        return $this->belongsTo(Trailer::class, 'connected_uuid', 'uuid');
    }
}
