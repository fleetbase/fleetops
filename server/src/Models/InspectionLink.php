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

    /**
     * How long a link lasts when nobody chooses: long enough to send one the
     * evening before a pre-trip, short enough that a forgotten link does not
     * stay live for good.
     */
    public const DEFAULT_TTL_HOURS = 72;

    /** How many digits a PIN has. */
    public const PIN_LENGTH = 6;

    /** How many wrong PINs a link takes before it locks. */
    public const MAX_PIN_ATTEMPTS = 5;

    protected $fillable = [
        'company_uuid',
        'inspection_form_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'assignee_uuid',
        'created_by_uuid',
        'token_hash',
        'token',
        'pin_hash',
        'pin',
        'pin_attempts',
        'pin_sent_via',
        'pin_sent_at',
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
        // Kept so the console can show the PIN again, like the link; the hash
        // beside it is what a guess is checked against.
        'pin'            => 'encrypted',
        'pin_attempts'   => 'integer',
        'pin_sent_at'    => 'datetime',
        'single_use'     => 'boolean',
        'expires_at'     => 'datetime',
        'last_viewed_at' => 'datetime',
        'used_at'        => 'datetime',
        'meta'           => Json::class,
    ];

    protected $with = ['form', 'driver', 'vehicle', 'assignee'];

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

    /** Whoever in the organisation the link is meant for, if anyone. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    /** A new PIN: six digits from a cryptographically secure source. */
    public static function generatePin(): string
    {
        return str_pad((string) random_int(0, (10 ** static::PIN_LENGTH) - 1), static::PIN_LENGTH, '0', STR_PAD_LEFT);
    }

    /** Give the link a PIN, and forget any wrong guesses at the last one. */
    public function setPin(string $pin): void
    {
        $this->pin_hash     = password_hash($pin, PASSWORD_BCRYPT);
        $this->pin          = $pin;
        $this->pin_attempts = 0;
    }

    /** Links minted before PINs existed have none, and ask for none. */
    public function hasPin(): bool
    {
        return !empty($this->pin_hash);
    }

    public function pinAttemptsLeft(): int
    {
        return max(0, static::MAX_PIN_ATTEMPTS - (int) $this->pin_attempts);
    }

    /**
     * Check a PIN given for this link: `ok`, `missing`, `wrong` or `locked`.
     *
     * A wrong guess is counted with an atomic increment, so guesses made at
     * the same moment cannot all read the same count, and the link locks when
     * the count reaches MAX_PIN_ATTEMPTS. A right one clears the count.
     */
    public function verifyPin(?string $pin): string
    {
        if (!$this->hasPin()) {
            return 'ok';
        }

        if ($this->status === 'locked') {
            return 'locked';
        }

        $pin = preg_replace('/\D/', '', (string) $pin);

        if ($pin === '') {
            return 'missing';
        }

        if (password_verify($pin, $this->pin_hash)) {
            if ($this->pin_attempts) {
                $this->forceFill(['pin_attempts' => 0])->save();
            }

            return 'ok';
        }

        static::query()->whereKey($this->getKey())->increment('pin_attempts');
        $this->refresh();

        if ($this->pin_attempts >= static::MAX_PIN_ATTEMPTS) {
            static::query()->whereKey($this->getKey())->update(['status' => 'locked']);
            $this->refresh();

            return 'locked';
        }

        return 'wrong';
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

    /**
     * Take a single-use link for one submission, atomically.
     *
     * Checking `isUsable()` and then marking the link used once the submission
     * was saved left a window in which two submits at the same moment could
     * both pass the check. A claim is one conditional update instead: the
     * database lets exactly one of them set `used_at`, and the other finds
     * nothing left to update.
     */
    public function claim(?string $ip = null, ?string $userAgent = null): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', 'active')
            ->whereNull('used_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->update(['used_at' => now(), 'used_ip' => $ip, 'used_user_agent' => $userAgent]);

        if ($claimed === 1) {
            $this->refresh();
        }

        return $claimed === 1;
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
