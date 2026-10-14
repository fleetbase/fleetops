<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer as CustomerModel;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Lightweight Customer resource for index views.
 * Handles polymorphic customer types (Contact, Vendor, etc.) with minimal data.
 */
class Customer extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        return [
            'id'           => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'         => $this->when($isInternal, $this->uuid),
            'public_id'    => $this->when($isInternal, $this->public_id),
            'company_uuid' => $this->when($isInternal, $this->company_uuid),
            'name'         => $this->name,
            'phone'        => $this->phone ?? null,
            'email'        => $this->email ?? null,
            'photo_url'    => $this->resource instanceof Vendor ? $this->logo_url : ($this->photo_url ?? null),
        ];
    }

    /**
     * Eager loads the file each customer type's `photo_url` reads (a contact's
     * photo, a vendor's logo), so listing orders does not query once per row.
     */
    public static function withPhoto(MorphTo $relation): MorphTo
    {
        return $relation->morphWith([
            Contact::class       => ['photo'],
            CustomerModel::class => ['photo'],
            Vendor::class        => ['logo'],
        ]);
    }
}
