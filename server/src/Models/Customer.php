<?php

namespace Fleetbase\FleetOps\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Fleet-Ops customer.
 *
 * A thin specialization of {@see Contact} that is scoped to rows with
 * `type='customer'` and forces that type on create. This is what the public
 * customer API surface (`/v1/customers/...`) operates on.
 *
 * Mirrors {@see \Fleetbase\Storefront\Models\Customer} but lives directly in
 * FleetOps so that customer auth + customer-scoped orders work without the
 * Storefront publishable-key + Store/Network gating.
 */
class Customer extends Contact
{
    /**
     * The key to use in the payload responses.
     */
    protected string $payloadKey = 'customer';

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->type = 'customer';
        });

        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', 'customer');
        });
    }

    /**
     * Orders owned by this customer.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_uuid')
            ->whereNull('deleted_at')
            ->withoutGlobalScopes();
    }

    /**
     * Find a customer by either a `customer_xxx` or `contact_xxx` public id.
     */
    public static function findFromCustomerId(string $publicId): ?self
    {
        if (Str::startsWith($publicId, 'customer')) {
            $publicId = Str::replaceFirst('customer', 'contact', $publicId);
        }

        return static::where('public_id', $publicId)->first();
    }
}
