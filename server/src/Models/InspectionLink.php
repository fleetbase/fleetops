<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InspectionLink extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasMetaAttributes;
    use SoftDeletes;

    protected $table = 'inspection_links';
    protected $publicIdType = 'inspection_link';

    protected $fillable = [
        'company_uuid',
        'inspection_form_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'created_by_uuid',
        'token_hash',
        'status',
        'single_use',
        'expires_at',
        'last_viewed_at',
        'used_at',
        'used_ip',
        'used_user_agent',
        'meta',
    ];

    protected $casts = [
        'single_use'     => 'boolean',
        'expires_at'     => 'datetime',
        'last_viewed_at' => 'datetime',
        'used_at'        => 'datetime',
        'meta'           => Json::class,
    ];

    protected $with = ['form', 'driver', 'vehicle'];

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(InspectionForm::class, 'inspection_form_uuid', 'uuid');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function isUsable(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return !($this->single_use && $this->used_at);
    }

    public function markViewed(): void
    {
        $this->forceFill(['last_viewed_at' => now()])->save();
    }

    public function markUsed(?string $ip = null, ?string $userAgent = null): void
    {
        $this->forceFill([
            'used_at'         => now(),
            'used_ip'         => $ip,
            'used_user_agent' => $userAgent,
        ])->save();
    }
}
