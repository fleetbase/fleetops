<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DeviceInstallation extends Model
{
    use HasUuid;
    protected $fillable = ['company_uuid', 'device_uuid', 'attachable_type', 'attachable_uuid', 'active_device_uuid', 'installed_at', 'removed_at', 'source', 'metadata'];
    protected $casts    = ['attachable_type' => PolymorphicType::class, 'installed_at' => 'datetime', 'removed_at' => 'datetime', 'metadata' => Json::class];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_uuid', 'uuid');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'attachable_type', 'attachable_uuid');
    }
}
