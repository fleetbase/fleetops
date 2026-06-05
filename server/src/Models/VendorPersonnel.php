<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPersonnel extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'vendor_personnels';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['vendor_uuid', 'contact_uuid', 'role', 'status', 'invited_by_uuid'];

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The vendor.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The contact which is a personnel of this vendor.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The Fleetbase user who invited this personnel.
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_uuid');
    }
}
