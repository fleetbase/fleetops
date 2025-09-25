<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasCustomFields;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Support\Str;

class Issue extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use SpatialTrait;
    use HasApiModelBehavior;
    use HasCustomFields;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'issues';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'issue';

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
    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'reported_by_uuid',
        'assigned_to_uuid',
        'vehicle_uuid',
        'driver_uuid',
        'issue_id',
        'location',
        'category',
        'type',
        'report',
        'title',
        'tags',
        'priority',
        'meta',
        'resolved_at',
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
        'location'        => Point::class,
        'meta'            => Json::class,
        'tags'            => Json::class,
        'resolved_at'     => 'date',
    ];

    /**
     * Filterable attributes/parameters.
     *
     * @var array
     */
    protected $filterParams = ['assignee', 'reporter'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['driver_name', 'vehicle_name', 'assignee_name', 'reporter_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignedTo()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Set the title attribute for the Issue model.
     *
     * If no value is provided, a default title is generated in the form:
     * "Issue reported on {created_at date, e.g., 15 Sep 25, 14:30}".
     *
     * @param string|null $value
     *
     * @return void
     */
    public function setTitleAttribute($value)
    {
        $created      = $this->created_at ?? now();
        $defaultTitle = 'Issue reported on ' . $created->format('d M y, H:i');

        $this->attributes['title'] = $value !== null ? trim($value) : $defaultTitle;
    }

    /**
     * Set a default status if none is provided, and always dasherize status input.
     *
     * @param string $value
     *
     * @return void
     */
    public function setStatusAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['status'] = 'pending';
        } else {
            $this->attributes['status'] = Str::slug($value);
        }
    }

    /**
     * Get the driver's name assigned to vehicle.
     *
     * @return string
     */
    public function getDriverNameAttribute()
    {
        return data_get($this, 'driver.name');
    }

    /**
     * Get the vehicless name.
     *
     * @return string
     */
    public function getVehicleNameAttribute()
    {
        return data_get($this, 'vehicle.display_name');
    }

    /**
     * Get the vehicless ID.
     *
     * @return string
     */
    public function getVehicleIdAttribute()
    {
        return data_get($this, 'vehicle.public_id');
    }

    /**
     * Get the reporter name.
     *
     * @return string
     */
    public function getReporterNameAttribute()
    {
        return data_get($this, 'reporter.name');
    }

    /**
     * Get the reporter ID.
     *
     * @return string
     */
    public function getReporterIdAttribute()
    {
        return data_get($this, 'reporter.public_id');
    }

    /**
     * Get the assignee name.
     *
     * @return string
     */
    public function getAssigneeNameAttribute()
    {
        return data_get($this, 'assignee.name');
    }

    /**
     * Get the assignee ID.
     *
     * @return string
     */
    public function getAssigneeIdAttribute()
    {
        return data_get($this, 'assignee.public_id');
    }

    public static function createFromImport(array $row, bool $saveInstance = false): Issue
    {
        // Filter array for null key values
        $row = array_filter($row);

        // Get issue columns
        $priority      = Utils::or($row, ['priority', 'level', 'urgency']);
        $report        = Utils::or($row, ['report', 'details', 'issue', 'content']);
        $category      = Utils::or($row, ['category', 'issue_category']);
        $type          = Utils::or($row, ['type', 'issue_type']);
        $reporter      = Utils::or($row, ['reporter', 'report', 'reporter_name', 'reported_by', 'reported_by_name']);
        $assignee      = Utils::or($row, ['assignee', 'assigned_to', 'assignee_name', 'assigned_to_name']);
        $vehicle       = Utils::or($row, ['vehicle', 'vehicle_name']);
        $driver        = Utils::or($row, ['driver', 'driver_name']);

        // Resolve relations
        $assigneeUser = is_string($assignee) ? User::where('name', 'like', '%' . $assignee . '%')->where('company_uuid', session('user'))->first() : null;
        $driverUser   = is_string($driver) ? Driver::findByIdentifier($driver) : null;
        $reporterUser = is_string($reporter) ? User::where('name', 'like', '%' . $reporter . '%')->where('company_uuid', session('user'))->first() : null;
        $vehicle      = is_string($vehicle) ? Vehicle::findByName($vehicle) : null;

        // Get location
        $latitude       = Utils::or($row, ['latitude', 'lat']);
        $longitude      = Utils::or($row, ['longitude', 'lng', 'long']);

        if ($latitude && $longitude) {
            $location = new Point($latitude, $longitude);
        } else {
            $location = Utils::getPointFromMixed(Utils::arrayFrom(data_get($row, 'location')));
        }

        // Create issue
        $issue = new static([
            'company_uuid' => session('company'),
            'priority'     => $priority,
            'report'       => $report,
            'category'     => $category,
            'type'         => $type,
            'location'     => Utils::parsePointToWkt($location ?? new Point(0, 0)),
            'status'       => 'pending',
        ]);

        if ($assigneeUser) {
            $issue->assigned_to_uuuid = $assigneeUser->uuid;
        }

        if ($reporterUser) {
            $issue->reported_by_uuid = $reporterUser->uuid;
        }

        if ($driverUser) {
            $issue->driver_uuid = $driverUser->uuid;
        }

        if ($vehicle) {
            $issue->vehicle_uuid = $vehicle->uuid;
        }

        if ($saveInstance === true) {
            $issue->save();
        }

        return $issue;
    }
}
