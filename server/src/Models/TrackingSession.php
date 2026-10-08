<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A device that has verified on the public tracking page. The cookie's value is never
 * stored, only its SHA-256 in `token_hash`.
 */
class TrackingSession extends Model
{
    use HasUuid;

    protected $table = 'tracking_sessions';

    protected $fillable = ['token_hash', 'ip', 'user_agent', 'last_seen_at', 'expires_at', 'revoked_at'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function grants(): HasMany
    {
        return $this->hasMany(TrackingSessionGrant::class, 'tracking_session_uuid', 'uuid');
    }
}
