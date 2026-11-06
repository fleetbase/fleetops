<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters shared by every query that finds drivers for an order: the order's own company,
 * drivers not already on a job, and the vehicle class a rider booked.
 */
class DriverMatching
{
    /**
     * A driver whose current job has one of these statuses is free again.
     */
    public const FREE_JOB_STATUSES = ['completed', 'canceled', 'no_show'];

    /**
     * Apply every filter for the order.
     */
    public static function forOrder(Builder $query, Order $order): Builder
    {
        static::inCompany($query, $order->company_uuid);
        static::notBusy($query);

        return static::forVehicleClass($query, static::vehicleClass($order));
    }

    /**
     * Drivers of the company, directly or through their user.
     */
    public static function inCompany(Builder $query, ?string $companyUuid): Builder
    {
        return $query->where(function ($query) use ($companyUuid) {
            $query->where($query->getModel()->getTable() . '.company_uuid', $companyUuid)
                ->orWhereHas('user', fn ($query) => $query->where('company_uuid', $companyUuid));
        });
    }

    /**
     * Drivers with no current job, or whose current job has finished. A job left behind by an
     * order that ended without clearing it doesn't keep the driver busy.
     */
    public static function notBusy(Builder $query): Builder
    {
        $drivers = $query->getModel()->getTable();

        return $query->where(function ($query) use ($drivers) {
            $query->whereNull($drivers . '.current_job_uuid')
                ->orWhereNotExists(function ($jobs) use ($drivers) {
                    $jobs->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.uuid', $drivers . '.current_job_uuid')
                        ->whereNull('orders.deleted_at')
                        ->whereNotIn('orders.status', self::FREE_JOB_STATUSES);
                });
        });
    }

    /**
     * Drivers whose vehicle is of the class, compared without case. No class matches everyone.
     */
    public static function forVehicleClass(Builder $query, ?string $class): Builder
    {
        if ($class === null || trim($class) === '') {
            return $query;
        }

        return $query->whereHas('vehicle', fn ($query) => $query->whereRaw('LOWER(type) = ?', [strtolower(trim($class))]));
    }

    /**
     * The vehicle class booked for the order, if any.
     */
    public static function vehicleClass(Order $order): ?string
    {
        $class = data_get($order->meta, 'vehicle_class');

        return is_string($class) && trim($class) !== '' ? trim($class) : null;
    }
}
