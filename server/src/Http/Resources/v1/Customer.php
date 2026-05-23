<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\Company;
use Fleetbase\Support\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public API resource for FleetOps customers.
 *
 * Wraps a {@see \Fleetbase\FleetOps\Models\Contact} of `type='customer'` and
 * exposes the auth `token` field that login/signup endpoints attach.
 */
class Customer extends FleetbaseResource
{
    public function toArray($request): array
    {
        $this->loadMissing(['place', 'places']);

        return [
            'id'           => $this->when(Http::isInternalRequest(), $this->id, Str::replaceFirst('contact', 'customer', $this->public_id)),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'user_uuid'    => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'company_uuid' => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'public_id'    => $this->when(Http::isInternalRequest(), $this->public_id),
            'internal_id'  => $this->internal_id,
            'name'         => $this->name,
            'title'        => $this->title,
            'photo_url'    => $this->photo_url,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'address'      => data_get($this, 'place.address'),
            'addresses'    => $this->whenLoaded('places', fn () => Place::collection($this->places)),
            'token'        => $this->when($this->token, $this->token),
            'orders_count' => $this->getOrdersCount($request),
            'company'      => $this->when($this->company_uuid, fn () => $this->buildCompanyPayload()),
            'meta'         => data_get($this, 'meta', Utils::createObject()),
            'slug'         => $this->slug,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }

    private function getOrdersCount(Request $request): int
    {
        return Order::where('customer_uuid', $this->uuid)
            ->whereNull('deleted_at')
            ->withoutGlobalScopes()
            ->count();
    }

    /**
     * Public-safe projection of the customer's company. Returns the same
     * fields any caller could fetch through other public endpoints — name,
     * resolved currency, country, phone — plus the company's public id.
     * Currency falls back through company.currency → ledger base_currency
     * → "USD" via {@see Utils::getCompanyTransactionCurrency}.
     */
    private function buildCompanyPayload(): ?array
    {
        $company = Company::find($this->company_uuid);
        if (!$company) {
            return null;
        }

        return [
            'id'       => $company->public_id,
            'name'     => $company->name,
            'currency' => Utils::getCompanyTransactionCurrency($company),
            'country'  => $company->country,
            'phone'    => $company->phone,
        ];
    }
}
