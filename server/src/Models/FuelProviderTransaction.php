<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelProviderTransaction extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use Searchable;

    protected $table = 'fuel_provider_transactions';
    protected $publicIdType = 'fuel_provider_transaction';
    protected $searchableColumns = ['public_id', 'provider', 'provider_transaction_id', 'vehicle_card_id', 'station_name', 'trip_number'];
    protected $filterParams = ['provider', 'sync_status', 'vehicle', 'driver', 'order', 'fuel_report', 'connection'];

    protected $fillable = [
        'company_uuid',
        'fuel_provider_connection_uuid',
        'fuel_report_uuid',
        'vehicle_uuid',
        'driver_uuid',
        'order_uuid',
        'provider',
        'provider_transaction_id',
        'provider_vehicle_id',
        'vehicle_card_id',
        'internal_number',
        'structure_number',
        'plate_number',
        'trip_number',
        'station_name',
        'station_latitude',
        'station_longitude',
        'transaction_at',
        'volume',
        'metric_unit',
        'amount',
        'currency',
        'odometer',
        'sync_status',
        'matched_at',
        'normalized_payload',
        'raw_payload',
        'meta',
    ];

    protected $casts = [
        'transaction_at'      => 'datetime',
        'matched_at'          => 'datetime',
        'normalized_payload'  => Json::class,
        'raw_payload'         => Json::class,
        'meta'                => Json::class,
    ];

    protected $appends = ['vehicle_name', 'driver_name', 'fuel_report_id', 'station_location'];
    protected $hidden = ['vehicle', 'driver', 'fuelReport'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FuelProviderConnection::class, 'fuel_provider_connection_uuid', 'uuid');
    }

    public function fuelReport(): BelongsTo
    {
        return $this->belongsTo(FuelReport::class, 'fuel_report_uuid', 'uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }

    public function getVehicleNameAttribute(): ?string
    {
        return data_get($this, 'vehicle.display_name');
    }

    public function getDriverNameAttribute(): ?string
    {
        return data_get($this, 'driver.name');
    }

    public function getFuelReportIdAttribute(): ?string
    {
        return data_get($this, 'fuelReport.public_id');
    }

    public function getStationLocationAttribute(): ?Point
    {
        if (!$this->station_latitude || !$this->station_longitude) {
            return null;
        }

        return new Point($this->station_latitude, $this->station_longitude);
    }
}
