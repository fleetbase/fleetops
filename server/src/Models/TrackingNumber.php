<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\TrackingCode;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

class TrackingNumber extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SendsWebhooks;
    use TracksApiCredential;
    use SpatialTrait;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'tracking_numbers';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'track';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'qr_code', 'barcode', 'status_uuid'];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'location'   => Point::class,
        'owner_type' => PolymorphicType::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['last_status', 'last_status_code', 'last_status_updated_at', 'type'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['status'];

    /**
     * Tracking number status.
     */
    public function getLastStatusAttribute()
    {
        $this->load('status');

        return data_get($this, 'status.status');
    }

    /**
     * Tracking number status code.
     */
    public function getLastStatusCodeAttribute()
    {
        $this->load('status');

        return data_get($this, 'status.code');
    }

    /**
     * Datetime of last status update.
     */
    public function getLastStatusUpdatedAtAttribute()
    {
        $this->load('status');

        return data_get($this, 'status.created_at');
    }

    /**
     * Get if last status completes activity.
     */
    public function getLastStatusCompleteAttribute()
    {
        $this->load('status');

        return data_get($this, 'status.complete');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->hasOne(TrackingStatus::class)->latest()->without(['trackingNumber']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statuses()
    {
        return $this->hasMany(TrackingStatus::class)->without(['trackingNumber']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'owner_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function entity()
    {
        return $this->belongsTo(Entity::class, 'owner_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid')->withoutGlobalScopes();
    }

    /**
     * @return string
     */
    public function getTypeAttribute()
    {
        return Utils::getTypeFromClassName($this->owner_type);
    }

    /**
     * Generates a fleetbase tracking number.
     *
     * @var string
     */
    public static function generateTrackingNumber($region = 'SG', $length = 10): string
    {
        $company     = \Fleetbase\Models\Company::where('uuid', session('company'))->withoutGlobalScopes()->first();
        $companyName = $company ? strtoupper(substr($company->name, 0, 3)) : null;
        $number      = $companyName ?? 'FLB';

        for ($i = 0; $i < $length; $i++) {
            $number .= mt_rand(0, 9);
        }

        return $number . strtoupper($region);
    }

    /**
     * Generates a unique fleetbase tracking number.
     *
     * @var string
     */
    public static function generateNumber($region = 'SG', $length = 10)
    {
        $n  = static::generateTrackingNumber($region, $length);
        $tr = static::where('tracking_number', $n)
            ->withTrashed()
            ->first();
        while (is_object($tr) && $n == $tr->tracking_number) {
            $n = static::generateTrackingNumber($region, $length);
        }

        return $n;
    }

    /**
     * Find a model by its public_id key or throw an exception.
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|static|static[]
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function findTrackingOrFail($id)
    {
        $result = static::query()
            ->select(['*'])
            ->with(['status'])
            ->where(function ($q) use ($id) {
                $q->where('public_id', $id);
                $q->orWhere('tracking_number', $id);
                $q->orWhere('uuid', $id);
            })
            ->first();

        if (!is_null($result)) {
            return $result;
        }

        throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(static::class, $id);
    }

    /**
     * The record a scanned code points at, within one company: the order, waypoint,
     * entity or place that owns the tracking number.
     *
     * Accepts every format TrackingCode::parse() reads, including the bare owner uuid
     * printed on labels before codes carried a url, and a bare tracking number or public
     * id from a barcode or typed by hand. Without a company nothing resolves.
     */
    public static function findOwnerByCode(?string $code, ?string $companyUuid): ?\Illuminate\Database\Eloquent\Model
    {
        if (empty($companyUuid)) {
            return null;
        }

        $parsed = TrackingCode::parse($code);

        if ($parsed['uuid']) {
            return static::ownerOf(static::firstForCode('owner_uuid', $parsed['uuid'], $companyUuid));
        }

        $number = $parsed['tracking_number'] ?? $parsed['reference'];
        if ($number) {
            $owner = static::ownerOf(static::firstForCode('tracking_number', $number, $companyUuid));

            // A url names its tracking number deliberately, so an unknown one is a miss,
            // not a cue to fall back to the owner it also names, which must agree.
            if ($owner || $parsed['tracking_number']) {
                return $owner && (!$parsed['public_id'] || data_get($owner, 'public_id') === $parsed['public_id']) ? $owner : null;
            }
        }

        $publicId = $parsed['public_id'] ?? $parsed['reference'];

        return $publicId ? static::ownerOf(static::firstForOwnerPublicId($publicId, $companyUuid)) : null;
    }

    protected static function firstForCode(string $column, string $value, string $companyUuid): ?self
    {
        return static::where('company_uuid', $companyUuid)->where($column, $value)->first();
    }

    protected static function firstForOwnerPublicId(string $publicId, string $companyUuid): ?self
    {
        // The public id's prefix names the owner's model, so only that table is searched.
        $prefix     = Str::contains($publicId, '_') ? Str::before($publicId, '_') : null;
        $ownerClass = [
            'order'    => Order::class,
            'waypoint' => Waypoint::class,
            'entity'   => Entity::class,
            'place'    => Place::class,
        ][$prefix] ?? null;

        if (!$ownerClass) {
            return null;
        }

        return static::where('company_uuid', $companyUuid)
            // Rows inserted raw (waypoints, entities) store the class with a leading slash.
            ->whereIn('owner_type', [$ownerClass, '\\' . $ownerClass])
            ->whereIn('owner_uuid', $ownerClass::withoutGlobalScopes()->select('uuid')->where('public_id', $publicId))
            ->first();
    }

    protected static function ownerOf(?self $trackingNumber): ?\Illuminate\Database\Eloquent\Model
    {
        return $trackingNumber?->owner;
    }

    public function updateOwnerStatus(?TrackingStatus $trackingStatus = null)
    {
        $trackingStatus = $trackingStatus ?? $this->load(['status'])->getRelationValue('status');
        // update status on owner
        $status = strtolower($trackingStatus->code);
        $owner  = $this->load(['owner'])->getRelationValue('owner');

        if ($owner && static::ownerHasStatusColumn($owner) && $owner->isFillable('status') && $owner->status !== $status) {
            $this->owner->status = $status;

            $this->owner->save();
        }

        return $this;
    }

    public static function insertGetUuid($values = [], ?Model $owner = null)
    {
        // Read before the fillable filter below drops it. Callers that insert the owner
        // with a raw query, and so have no model to pass, hand its public_id over here.
        $ownerPublicId = $owner ? data_get($owner, 'public_id') : ($values['owner_public_id'] ?? null);

        $instance   = new static();
        $fillable   = $instance->getFillable();
        $insertKeys = array_keys($values);
        // clean insert data
        foreach ($insertKeys as $key) {
            if (!in_array($key, $fillable)) {
                unset($values[$key]);
            }
        }

        $values['uuid']         = $uuid = static::newUuid();
        $values['public_id']    = static::newPublicId();
        $values['_key']         = session('api_key') ?? 'console';
        $values['created_at']   = static::currentTimestamp();
        $values['company_uuid'] = session('company');

        if ($owner) {
            $values['owner_uuid'] = $owner->uuid;
            $values['owner_type'] = Utils::getMutationType($owner);
        }

        $values['tracking_number'] = static::newTrackingNumber($values['region'] ?? 'SG');
        $values                    = array_merge($values, static::codeImages($values['tracking_number'], $ownerPublicId));

        if (isset($values['meta']) && (is_object($values['meta']) || is_array($values['meta']))) {
            $values['meta'] = json_encode($values['meta']);
        }

        $result = static::insertTrackingNumber($values);

        if (!$result) {
            return false;
        }

        $ownerTypeName = class_basename($values['owner_type']);

        // owners may define their own initial status (e.g. orders with a configured lifecycle)
        $initialStatus = $owner && method_exists($owner, 'getInitialTrackingStatus') ? $owner->getInitialTrackingStatus() : null;
        $initialCode   = is_array($initialStatus) && !empty($initialStatus['code']) ? $initialStatus['code'] : null;

        // create initial status
        $trackingStatusId = static::createInitialTrackingStatus([
            'tracking_number_uuid' => $uuid,
            'status'               => $initialCode ? $initialStatus['status'] : Str::title($ownerTypeName . ' created'),
            'details'              => $initialCode ? $initialStatus['details'] : 'New ' . Str::lower($ownerTypeName) . ' created.',
            'location'             => $values['location'] ?? Utils::parsePointToWkt(new Point(0, 0)),
            'code'                 => $initialCode ? TrackingStatus::prepareCode($initialCode) : 'CREATED',
        ]);

        // update status of tracking number
        static::updateTrackingStatusUuid($uuid, $trackingStatusId);

        // update owner status
        if ($owner && $owner instanceof Model && static::ownerHasStatusColumn($owner) && $owner->isFillable('status')) {
            // runs event cycles
            // $model->update([ 'status' => 'created' ]);

            // silent update
            static::updateOwnerStatusColumn($owner, $initialCode ?? 'created');
        }

        return $uuid;
    }

    protected static function newUuid(): string
    {
        return static::generateUuid();
    }

    protected static function newPublicId(): string
    {
        return static::generatePublicId('track');
    }

    protected static function currentTimestamp(): string
    {
        return Carbon::now()->toDateTimeString();
    }

    protected static function newTrackingNumber(string $region): string
    {
        return static::generateNumber($region);
    }

    /**
     * The base64 PNGs printed on a label: a QR code carrying the tracking url (see
     * TrackingCode) and a Code 128 barcode carrying the bare tracking number.
     *
     * @return array{qr_code: string, barcode: string}
     */
    public static function codeImages(string $trackingNumber, ?string $ownerPublicId = null): array
    {
        return [
            // Medium error correction: labels get scuffed, and the url fits comfortably.
            'qr_code' => static::newBarcode(TrackingCode::qrContent($trackingNumber, $ownerPublicId), 'QRCODE,M'),
            'barcode' => static::newBarcode(TrackingCode::barcodeContent($trackingNumber), 'C128'),
        ];
    }

    protected static function newBarcode(string $value, string $type): string
    {
        if ($type === 'C128') {
            // 2px modules, 60px tall: a typical tracking number renders ~330px wide, inside
            // the label's 360px cap, so it prints at its own resolution.
            return DNS1D::getBarcodePNG($value, $type, 2, 60);
        }

        return DNS2D::getBarcodePNG($value, $type);
    }

    protected static function insertTrackingNumber(array $values): bool
    {
        return static::insert($values);
    }

    protected static function createInitialTrackingStatus(array $values)
    {
        return TrackingStatus::insertGetUuid($values);
    }

    protected static function updateTrackingStatusUuid(string $uuid, mixed $trackingStatusId): void
    {
        TrackingNumber::where('uuid', $uuid)->update(['status_uuid' => $trackingStatusId]);
    }

    protected static function updateOwnerStatusColumn(Model $owner, string $status): void
    {
        DB::table($owner->getTable())->where('uuid', $owner->uuid)->update(['status' => $status]);
    }

    protected static function ownerHasStatusColumn(Model $owner): bool
    {
        return Schema::hasColumn($owner->getTable(), 'status');
    }
}
