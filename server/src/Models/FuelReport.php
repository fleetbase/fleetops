<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;

class FuelReport extends Model
{
    use HasUuid;
    use TracksApiCredential;
    use HasPublicId;
    use HasApiModelBehavior;
    use SpatialTrait;
    use Searchable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'fuel_reports';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'fuel_report';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['report', 'vehicle.name', 'driver.name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'reported_by_uuid',
        'report',
        'odometer',
        'location',
        'amount',
        'currency',
        'volume',
        'metric_unit',
        'meta',
        'status',
    ];

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
        'location' => Point::class,
        'meta'     => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['vehicle_name', 'driver_name', 'reporter_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['driver', 'vehicle'];

    /**
     * Filterable attributes/parameters.
     *
     * @var array
     */
    protected $filterParams = ['type', 'status', 'reporter'];

    /**
     * Set the parcel fee as only numbers.
     *
     * @void
     */
    public function setAmountAttribute($value)
    {
        $this->attributes['amount'] = Utils::numbersOnly($value);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function driver()
    {
        return $this->belongsTo(Driver::class)->without(['vehicle']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class)->without(['driver']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reportedBy()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by_uuid');
    }

    /**
     * Get the driver's name assigned to vehicle.
     *
     * @var Model
     */
    public function getDriverNameAttribute()
    {
        return data_get($this, 'driver.name');
    }

    /**
     * Get the vehicless name.
     *
     * @var Model
     */
    public function getVehicleNameAttribute()
    {
        return data_get($this, 'vehicle.display_name');
    }

    /**
     * Get the vehicless name.
     *
     * @var Model
     */
    public function getReporterNameAttribute()
    {
        return data_get($this, 'reportedBy.name');
    }

    public static function createFromImport(array $row, bool $saveInstance = false): FuelReport
    {
        // Filter array for null key values
        $row = array_filter($row);

        // Get fuelReport columns
        $reporter = Utils::or($row, ['report', 'reporter', 'reported']);
        $driver = Utils::or($row, ['driver', 'driver name']);

        $reporterUser = User::where('name', 'like', '%' . $reporter . '%')->where('company_uuid', session('user'))->first();
        if ($reporterUser) {
            $row['reported_by_uuid'] = $reporterUser->uuid;
        }

        $driverUser = User::where('name', 'like', '%' . $driver. '%')->where('company_uuid', session('user'))->first();
        if ($driverUser) {
            $row['driver_uuid'] = $driverUser->uuid;
        }

        // Create fuelReport
        $fuelReport = new static([
            'company_uuid' => session('company'),
            'reporter'     => $reporterUser,
            'odometer'     => 0,
            'driver'       => $driverUser,
            'location'     => Utils::parsePointToWkt(new Point(0, 0)),
        ]);

        if ($saveInstance === true) {
            $fuelReport->save();
        }

        return $fuelReport;
    }
}
