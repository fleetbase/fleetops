<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters shared by every query that finds drivers for an order: the order's own company,
 * drivers not already on a job, and the vehicle type the order needs.
 */
class DriverMatching
{
    /**
     * A driver whose current job has one of these statuses is free again.
     */
    public const FREE_JOB_STATUSES = ['completed', 'canceled', 'no_show'];

    /**
     * Available, online drivers who could take the order, with their `distance` from the pickup in
     * metres. Within the order's ping radius, unless testing, which skips the position checks.
     */
    public static function nearby(Order $order, Point $pickup, bool $testing = false): Builder
    {
        $query = Driver::query()
            ->withoutGlobalScopes()
            ->where(['status' => 'available', 'online' => 1])
            ->whereNull('deleted_at');

        if (!$testing) {
            $query->whereNotNull('location')
                ->whereRaw('ST_Y(location) BETWEEN -90 AND 90 AND ST_X(location) BETWEEN -180 AND 180 AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)')
                ->distanceSphere('location', $pickup, $order->getAdhocPingDistance())
                ->distanceSphereValue('location', $pickup);
        }

        // An app store reviewer's driver is never offered a real order.
        NavigatorReview::excludeFrom($query);

        return static::forOrder($query, $order);
    }

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
     * The vehicle type the order needs, if any (`required_vehicle_type`).
     */
    public static function vehicleClass(Order $order): ?string
    {
        $class = $order->required_vehicle_type;

        return is_string($class) && trim($class) !== '' ? trim($class) : null;
    }
}
