<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelProviderConnection extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use Searchable;

    protected $table             = 'fuel_provider_connections';
    protected $publicIdType      = 'fuel_provider_connection';
    protected $searchableColumns = ['public_id', 'name', 'provider', 'status', 'environment'];
    protected $filterParams      = ['provider', 'status', 'environment'];

    protected $fillable = [
        'company_uuid',
        'provider',
        'name',
        'environment',
        'status',
        'credentials',
        'sync_settings',
        'last_sync_state',
        'last_synced_at',
        'last_tested_at',
        'last_error',
        'meta',
    ];

    protected $casts = [
        'credentials'     => Json::class,
        'sync_settings'   => Json::class,
        'last_sync_state' => Json::class,
        'last_synced_at'  => 'datetime',
        'last_tested_at'  => 'datetime',
        'meta'            => Json::class,
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(FuelProviderTransaction::class, 'fuel_provider_connection_uuid', 'uuid');
    }
}
