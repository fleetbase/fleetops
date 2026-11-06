<?php

namespace Fleetbase\FleetOps\Support\AdhocDispatch;

use Fleetbase\FleetOps\Models\OrderOffer;
use Fleetbase\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Which adhoc orders a driver sees when they list orders near them, so they only see orders they
 * can take. An unassigned adhoc order shows when the driver holds an open offer for it, or when
 * its strategy lists orders openly and the driver hasn't declined it. Other orders are left alone.
 *
 * The strategy is worked out in SQL with the same order as `AdhocDispatch::nameFor()`: the
 * order's own, then its config's, then the company default.
 */
class AdhocOfferListing
{
    public static function apply(Builder $query, string $driverUuid, ?Company $company): Builder
    {
        $orders   = $query->getModel()->getTable();
        $names    = AdhocDispatch::names();
        $open     = AdhocDispatch::openlyListed();
        $inNames  = implode(', ', array_fill(0, count($names), '?'));
        $strategy = "CASE WHEN {$orders}.adhoc_strategy IN ({$inNames}) THEN {$orders}.adhoc_strategy"
            . " WHEN (SELECT oc.adhoc_strategy FROM order_configs oc WHERE oc.uuid = {$orders}.order_config_uuid) IN ({$inNames})"
            . " THEN (SELECT oc.adhoc_strategy FROM order_configs oc WHERE oc.uuid = {$orders}.order_config_uuid) ELSE ? END";
        $bindings = [...$names, ...$names, AdhocDispatch::companyDefault($company)];

        return $query->where(function ($query) use ($orders, $driverUuid, $open, $strategy, $bindings) {
            $query->whereNull("{$orders}.adhoc")
                ->orWhere("{$orders}.adhoc", 0)
                ->orWhereNotNull("{$orders}.driver_assigned_uuid")
                ->orWhereExists(fn ($offers) => static::offersOf($offers, $orders, $driverUuid)
                    ->where('order_offers.status', OrderOffer::PENDING)
                    ->where(fn ($expiry) => $expiry->whereNull('order_offers.expires_at')->orWhere('order_offers.expires_at', '>', now())));

            if ($open !== []) {
                $query->orWhere(function ($query) use ($orders, $driverUuid, $open, $strategy, $bindings) {
                    $query->whereRaw("({$strategy}) IN (" . implode(', ', array_fill(0, count($open), '?')) . ')', [...$bindings, ...$open])
                        ->whereNotExists(fn ($offers) => static::offersOf($offers, $orders, $driverUuid)->where('order_offers.status', OrderOffer::DECLINED));
                });
            }
        });
    }

    /**
     * Put each order's open offer for the driver on it (`adhocOffer`), for the order resource's `adhoc_offer`.
     */
    public static function attach(iterable $orders, string $driverUuid): void
    {
        // A page of results is a paginator, whose array form wraps the orders.
        $orders = $orders instanceof AbstractPaginator ? $orders->getCollection() : collect($orders);
        $offers = OrderOffer::where('driver_uuid', $driverUuid)
            ->whereIn('order_uuid', $orders->pluck('uuid')->filter()->all())
            ->where('status', OrderOffer::PENDING)
            ->get()
            ->keyBy('order_uuid');

        foreach ($orders as $order) {
            $order->setRelation('adhocOffer', $offers->get($order->uuid));
        }
    }

    /**
     * The driver's open offer as the order resource shows it.
     */
    public static function present(?OrderOffer $offer): ?array
    {
        return $offer ? ['status' => $offer->status, 'expires_at' => $offer->expires_at?->toISOString()] : null;
    }

    protected static function offersOf($offers, string $orders, string $driverUuid)
    {
        return $offers->selectRaw('1')
            ->from('order_offers')
            ->whereColumn('order_offers.order_uuid', "{$orders}.uuid")
            ->where('order_offers.driver_uuid', $driverUuid)
            ->whereNull('order_offers.deleted_at');
    }
}
