<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PurchaseRate extends Model
{
    use HasUuid;
    use HasPublicId;
    use SendsWebhooks;
    use TracksApiCredential;
    use HasMetaAttributes;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'purchase_rates';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'rate';

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
    protected $fillable = ['_key', 'customer_uuid', 'customer_type', 'company_uuid', 'service_quote_uuid', 'transaction_uuid', 'payload_uuid', 'status', 'meta'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['customer_is_vendor', 'customer_is_contact', 'amount', 'currency', 'service_quote_id', 'order_id', 'transaction_id', 'customer_id'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Order rate was purchased for.
     *
     * @var Model
     */
    public function order()
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Service quote for purchased rate.
     *
     * @var Model
     */
    public function serviceQuote()
    {
        return $this->belongsTo(ServiceQuote::class);
    }

    /**
     * Transaction for purchased rate.
     *
     * @var Model
     */
    public function transaction()
    {
        return $this->belongsTo(\Fleetbase\Models\Transaction::class);
    }

    /**
     * Payload for the order.
     *
     * @var Model
     */
    public function payload()
    {
        return $this->belongsTo(Payload::class);
    }

    /**
     * Company who owns the order.
     *
     * @var Model
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * The customer if any for this place.
     *
     * @var Model
     */
    public function customer()
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid');
    }

    /**
     * True of the customer is a vendor `customer_is_vendor`.
     *
     * @var bool
     */
    public function getCustomerIsVendorAttribute()
    {
        return Str::contains(strtolower($this->customer_type), 'vendor');
    }

    /**
     * True of the customer is a contact `customer_is_contact`.
     *
     * @var bool
     */
    public function getCustomerIsContactAttribute()
    {
        return Str::contains(strtolower($this->customer_type), 'contact');
    }

    public function getAmountAttribute()
    {
        return data_get($this, 'serviceQuote.amount');
    }

    public function getCurrencyAttribute()
    {
        return data_get($this, 'serviceQuote.currency');
    }

    public function getServiceQuoteIdAttribute()
    {
        return data_get($this, 'serviceQuote.public_id');
    }

    public function getOrderIdAttribute()
    {
        return data_get($this, 'order.public_id');
    }

    public function getCustomerIdAttribute()
    {
        return data_get($this, 'customer.public_id');
    }

    public function getTransactionIdAttribute()
    {
        return data_get($this, 'transaction.public_id');
    }

    /**
     * @return \Fleetbase\Models\PurchaseRate|null
     */
    public static function resolveFromRequest(Request $request): ?PurchaseRate
    {
        $purchaseRate = $request->or(['order.purchase_rate_uuid', 'purchase_rate', 'purchase_rate_id', 'order.purchase_rate']);

        if (empty($purchaseRate)) {
            return null;
        }

        if (Utils::isUuid($purchaseRate)) {
            $purchaseRate = static::where('uuid', $purchaseRate)->first();
        }

        if (Utils::isPublicId($purchaseRate)) {
            $purchaseRate = static::where('public_id', $purchaseRate)->first();
        }

        return $purchaseRate;
    }
}
