<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class InspectionLink extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasMetaAttributes;
    use SoftDeletes;

    protected $table        = 'inspection_links';
    protected $publicIdType = 'inspection_link';

    protected $fillable = [
        'company_uuid',
        'inspection_form_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'created_by_uuid',
        'token_hash',
        'token',
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
        // The token is a capability URL, kept so an operator can re-read a link
        // they minted; the hash beside it is what a public request is resolved
        // through. Encrypted at rest with the application key.
        'token'          => 'encrypted',
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

    /**
     * Why a link cannot be used, or `active` when it can.
     *
     * `status` records only what a person did to it — a revoked link is
     * `revoked` — so expiry and single-use exhaustion have to be read off the
     * timestamps. A list of links is unreadable without this: an expired link
     * and a live one both say `active` in the column.
     */
    public function getStateAttribute(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }

        if ($this->single_use && $this->used_at) {
            return 'used';
        }

        return 'active';
    }

    /** The path this link opens, or null for a link minted before tokens were kept. */
    public function getPathAttribute(): ?string
    {
        $token = $this->token;

        if (empty($token)) {
            return null;
        }

        $form = $this->form;

        return '/~/inspection?id=' . urlencode($form->public_id ?? $form->uuid) . '&token=' . urlencode($token);
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

    /** Take a link out of use without deleting the record of it. */
    public function revoke(): void
    {
        $this->forceFill(['status' => 'revoked'])->save();
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
