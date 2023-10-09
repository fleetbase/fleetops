<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceQuote extends Model
{
    use HasUuid;
    use HasPublicId;
    use SendsWebhooks;
    use TracksApiCredential;
    use Expirable;
    use HasMetaAttributes;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'quote';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'service_quotes';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The expiry datetime column.
     *
     * @var string
     */
    public static $expires_at = 'expired_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'request_id', 'company_uuid', 'service_rate_uuid', 'payload_uuid', 'amount', 'currency', 'meta', 'expired_at'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['service_rate_name'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'       => Json::class,
        'expired_at' => 'datetime',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['serviceRate'];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['facilitator'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(ServiceQuoteItem::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function serviceRate()
    {
        return $this->belongsTo(ServiceRate::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function payload()
    {
        return $this->belongsTo(Payload::class)->withoutGlobalScopes();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function integratedVendor()
    {
        return $this->belongsTo(IntegratedVendor::class);
    }

    /**
     * The service rate name for this quote.
     *
     * @var string
     */
    public function getServiceRateNameAttribute()
    {
        return data_get($this, 'serviceRate.service_name');
    }

    public function fromIntegratedVendor()
    {
        return (bool) $this->integrated_vendor_uuid || $this->hasMeta('from_integrated_vendor');
    }

    public static function fromLalamoveQuotation($quotation = null)
    {
        return Lalamove::serviceQuoteFromQuotation($quotation);
    }

    public static function resolveFromRequest(Request $request): ?ServiceQuote
    {
        $serviceQuote = $request->or(['order.service_quote_uuid', 'service_quote', 'service_quote_id', 'order.service_quote']);

        if (empty($serviceQuote)) {
            return null;
        }

        if (Str::isUuid($serviceQuote)) {
            $serviceQuote = static::where('uuid', $serviceQuote)->first();
        }

        if (Utils::isPublicId($serviceQuote)) {
            $serviceQuote = static::where('public_id', $serviceQuote)->first();
        }

        return $serviceQuote;
    }

    /**
     * Get the plural name of this model, either from the `pluralName` property or by inflecting the table name.
     *
     * @return string the plural name of this model
     */
    public function getPluralName(): string
    {
        if (isset($this->pluralName)) {
            return $this->pluralName;
        }

        if (isset($this->payloadKey)) {
            return Str::plural($this->payloadKey);
        }

        return Str::plural($this->getTable());
    }

    /**
     * Get the singular name of this model, either from the `singularName` property or by inflecting the table name.
     *
     * @return string the singular name of this model
     */
    public function getSingularName(): string
    {
        if (isset($this->singularName)) {
            return $this->singularName;
        }

        if (isset($this->payloadKey)) {
            return Str::singular($this->payloadKey);
        }

        return Str::singular($this->getTable());
    }
}
