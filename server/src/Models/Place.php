<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Place extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use Searchable;
    use SendsWebhooks;
    use TracksApiCredential;
    use SpatialTrait;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'places';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'place';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'street1', 'street2', 'country', 'province', 'district', 'city', 'postal_code', 'phone'];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        '_import_id',
        'company_uuid',
        'owner_uuid',
        'owner_type',
        'name',
        'type',
        'street1',
        'street2',
        'city',
        'province',
        'postal_code',
        'neighborhood',
        'district',
        'building',
        'security_access_code',
        'country',
        'location',
        'meta',
        'phone',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['country_name', 'address', 'address_html'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'id',
        '_key',
        'connect_company_uuid',
        'owner_uuid',
        'owner_type',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'     => Json::class,
        'location' => Point::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['vendor', 'contact', 'vendor_uuid', 'vendor_name'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function owner()
    {
        return $this->morphTo(__FILE__, 'owner_type', 'owner_uuid')->withDefault(
            [
                'name' => 'N/A',
            ]
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * Get the country data for the model instance.
     */
    public function getCountryDataAttribute(): array
    {
        return $this->fromCache(
            'country_data',
            function () {
                if (empty($this->country)) {
                    return [];
                }

                return Utils::getCountryData($this->country);
            }
        );
    }

    /**
     * Returns the full country name.
     */
    public function getCountryNameAttribute(): ?string
    {
        return data_get($this, 'country_data.name.common');
    }

    /**
     * Returns a Point instance from the location of the model.
     */
    public function getLocationAsPoint(): \Grimzy\LaravelMysqlSpatial\Types\Point
    {
        return Utils::getPointFromCoordinates($this->location);
    }

    /**
     * Fills empty address attributes with Google address attributes.
     *
     * @return \Fleetbase\FleetOps\Models\Place $this
     */
    public function fillWithGoogleAddress(\Geocoder\Provider\GoogleMaps\Model\GoogleAddress $address): Place
    {
        $formattedAddress = $address->getFormattedAddress();

        if (empty($this->street1) && $address) {
            $streetAddress = trim($address->getStreetAddress() ?? $address->getStreetNumber() . ' ' . $address->getStreetName());

            if (empty($streetAddress) && $formattedAddress) {
                // fallback use `formattedAddress`
                $streetAddress = explode(',', $formattedAddress, 3);
                $streetAddress = isset($streetAddress[2]) ? trim($streetAddress[0] . ', ' . $streetAddress[1]) : $formattedAddress;
            }

            $this->setAttribute('street1', $streetAddress);
        }

        if (empty($this->postal_code) && $address) {
            $this->setAttribute('postal_code', $address->getPostalCode());
        }

        if (empty($this->neighborhood) && $address) {
            $this->setAttribute('neighborhood', $address->getNeighborhood());
        }

        if (empty($this->city) && $address) {
            $this->setAttribute('city', $address->getLocality());
        }

        if (empty($this->building) && $address) {
            $this->setAttribute('building', $address->getStreetNumber());
        }

        if (empty($this->country) && $address) {
            $this->setAttribute('country', $address->getCountry() instanceof \Geocoder\Model\Country ? $address->getCountry()->getCode() : null);
        }

        if ($coordinates = $address->getCoordinates()) {
            $this->setAttribute('location', new \Grimzy\LaravelMysqlSpatial\Types\Point($coordinates->getLatitude(), $coordinates->getLongitude()));
        }

        return $this;
    }

    /**
     * Returns an array of address attributes using Google address object.
     */
    public static function getGoogleAddressArray(?\Geocoder\Provider\GoogleMaps\Model\GoogleAddress $address): array
    {
        $attributes = [];

        if (!$address instanceof \Geocoder\Provider\GoogleMaps\Model\GoogleAddress) {
            return $attributes;
        }

        $stretAddress = $address->getStreetAddress() ?? $address->getStreetNumber() . ' ' . $address->getStreetName();
        $coordinates  = $address->getCoordinates();

        $attributes['name']         = $stretAddress;
        $attributes['street1']      = $stretAddress;
        $attributes['postal_code']  = $address->getPostalCode();
        $attributes['neighborhood'] = $address->getNeighborhood();
        $attributes['city']         = $address->getLocality();
        $attributes['building']     = $address->getStreetNumber();
        $attributes['country']      = $address->getCountry() instanceof \Geocoder\Model\Country ? $address->getCountry()->getCode() : null;
        $attributes['location']     = new \Grimzy\LaravelMysqlSpatial\Types\Point($coordinates->getLatitude(), $coordinates->getLongitude());

        return $attributes;
    }

    /**
     * Create a new Place instance from a Google Address instance and optionally save it to the database.
     */
    public static function createFromGoogleAddress(\Geocoder\Provider\GoogleMaps\Model\GoogleAddress $address, bool $saveInstance = false): Place
    {
        $instance = (new static())->fillWithGoogleAddress($address);

        if ($saveInstance) {
            $instance->save();
        }

        return $instance;
    }

    /**
     * Inserts a new Place record into the database with attributes from a Google Maps address.
     *
     * @return string The UUID of the new record
     */
    public static function insertFromGoogleAddress(\Geocoder\Provider\GoogleMaps\Model\GoogleAddress $address)
    {
        $values = static::getGoogleAddressArray($address);

        return static::insertGetUuid($values);
    }

    /**
     * Create a new Place instance from a geocoding lookup.
     *
     * @param bool $saveInstance
     */
    public static function createFromGeocodingLookup(string $address, $saveInstance = false): Place
    {
        $results = \Geocoder\Laravel\Facades\Geocoder::geocode($address)->get();

        if ($results->isEmpty() || !$results->first()) {
            return (new static())->newInstance(['street1' => $address]);
        }

        return static::createFromGoogleAddress($results->first(), $saveInstance);
    }

    /**
     * Creates a new Place instance from given coordinates.
     *
     * @param array $attributes
     * @param bool  $saveInstance
     *
     * @return Place|false
     */
    public static function createFromCoordinates($coordinates, $attributes = [], $saveInstance = false)
    {
        $instance = new Place();

        $latitude  = Utils::getLatitudeFromCoordinates($coordinates);
        $longitude = Utils::getLongitudeFromCoordinates($coordinates);

        $instance->setAttribute('location', new \Grimzy\LaravelMysqlSpatial\Types\Point($latitude, $longitude));
        $instance->fill($attributes);

        $results = \Geocoder\Laravel\Facades\Geocoder::reverse($latitude, $longitude)->get();

        if ($results->isEmpty()) {
            return false;
        }

        $instance->fillWithGoogleAddress($results->first());

        if ($saveInstance) {
            $instance->save();
        }

        return $instance;
    }

    /**
     * Inserts a new place into the database using latitude and longitude coordinates.
     *
     * @param \Grimzy\LaravelMysqlSpatial\Types\Point|string|array $coordinates the coordinates to use for the new place
     *
     * @return mixed returns the UUID of the new place on success or false on failure
     */
    public static function insertFromCoordinates($coordinates)
    {
        $attributes = [];

        $point = Utils::getPointFromMixed($coordinates);

        if ($coordinates instanceof \Grimzy\LaravelMysqlSpatial\Types\Point) {
            $attributes['location'] = $coordinates;
            $latitude               = $coordinates->getLat();
            $longitude              = $coordinates->getLng();
        } elseif ($point instanceof \Grimzy\LaravelMysqlSpatial\Types\Point) {
            $attributes['location'] = $point;
            $latitude               = $point->getLat();
            $longitude              = $point->getLng();
        }

        $results = \Geocoder\Laravel\Facades\Geocoder::reverse($latitude, $longitude)->get();

        if (!$results->count() === 0) {
            return false;
        }

        $address = static::getGoogleAddressArray($results->first());
        $values  = array_merge($attributes, $address);

        return static::insertGetUuid($values);
    }

    /**
     * Creates a Place object from mixed input.
     *
     * @param array $attributes
     * @param bool  $saveInstance
     *
     * @return \App\Models\Place|false
     */
    public static function createFromMixed($place, $attributes = [], $saveInstance = true)
    {
        // If $place is a string
        if (is_string($place)) {
            // Check if $place is a valid public_id, return matching Place object if found
            if (Utils::isPublicId($place)) {
                return Place::where('public_id', $place)->first();
            }

            // Check if $place is a valid uuid, return matching Place object if found
            if (Str::isUuid($place)) {
                return Place::where('uuid', $place)->first();
            }

            // Attempt to find by address or name
            $resolvedFromSearch = static::query()
                ->where('company_uuid', session('company'))
                ->where(function ($q) use ($place) {
                    $q->where('street1', $place);
                    $q->orWhere('name', $place);
                })
                ->first();

            if ($resolvedFromSearch) {
                return $resolvedFromSearch;
            }

            // Return a new Place object created from a geocoding lookup
            return static::createFromGeocodingLookup($place, $saveInstance);
        }
        // If $place is an array of coordinates
        elseif (Utils::isCoordinatesStrict($place)) {
            return static::insertFromCoordinates($place, true);
        }
        // If $place is an array
        elseif (is_array($place)) {
            // If $place is an array of coordinates, create a new Place object
            if (Utils::isCoordinatesStrict($place)) {
                return static::createFromCoordinates($place, $attributes, $saveInstance);
            }

            // If $place has a valid uuid and a matching Place object exists, return the uuid
            if (isset($place['uuid']) && Str::isUuid($place['uuid']) && Place::where('uuid', $place['uuid'])->exists()) {
                return $place['uuid'];
            }

            // Otherwise, create a new Place object with the given attributes
            return Place::create($place);
        }
        // If $place is a GoogleAddress object
        elseif ($place instanceof \Geocoder\Provider\GoogleMaps\Model\GoogleAddress) {
            return static::createFromGoogleAddress($place, $saveInstance);
        }

        return false;
    }

    /**
     * Inserts a new place into the database from mixed data.
     *
     * @param mixed $place the data to use to create the new place
     *
     * @return string|bool the UUID of the newly created place or false if the place was not created
     */
    public static function insertFromMixed($place)
    {
        if (Utils::isCoordinatesStrict($place)) {
            // create a place from coordinates using reverse loopup
            return Place::insertFromCoordinates($place, true);
        } elseif (is_string($place)) {
            if (Utils::isPublicId($place)) {
                $resolvedPlace = Place::where('public_id', $place)->first();

                if ($resolvedPlace) {
                    return $resolvedPlace->uuid;
                }
            }

            if (Str::isUuid($place)) {
                $resolvedPlace = Place::where('uuid', $place)->first();

                if ($resolvedPlace) {
                    return $resolvedPlace->uuid;
                }
            }

            return Place::insertFromGeocodingLookup($place);
        } elseif (is_array($place) || is_object($place)) {
            // if place already exists just return uuid
            if (static::isValidPlaceUuid(data_get($place, 'uuid'))) {
                return data_get($place, 'uuid');
            }

            // if place already exists using `public_id` then resolve and return uuid
            if (static::isValidPlacePublicId(data_get($place, 'public_id'))) {
                $resolvedPlace = static::where('public_id', data_get($place, 'public_id'))->first();

                if ($resolvedPlace) {
                    return $resolvedPlace->uuid;
                }
            }

            $values = $place;

            // create a new place
            return static::insertGetUuid((array) $values);
        } elseif ($place instanceof \Geocoder\Provider\GoogleMaps\Model\GoogleAddress) {
            return static::insertFromGoogleAddress($place);
        }
    }

    public static function isValidPlaceUuid($uuid): bool
    {
        return is_string($uuid) && Str::isUuid($uuid) && Place::where('uuid', $uuid)->exists();
    }

    public static function isValidPlacePublicId($publicId): bool
    {
        return is_string($publicId) && Utils::isPublicId($publicId) && Place::where('public_id', $publicId)->exists();
    }

    /**
     * Inserts a new row into the database and returns the UUID of the inserted row.
     *
     * @param array $values Associative array of values to be inserted
     *
     * @return string|false Returns the UUID of the inserted row if successful, false otherwise
     */
    public static function insertGetUuid($values = [])
    {
        $instance   = new static();
        $fillable   = $instance->getFillable();
        $insertKeys = array_keys($values);
        // clean insert data
        foreach ($insertKeys as $key) {
            if (!in_array($key, $fillable)) {
                unset($values[$key]);
            }
        }

        $values['uuid']         = $uuid = static::generateUuid();
        $values['public_id']    = static::generatePublicId('place');
        $values['created_at']   = Carbon::now()->toDateTimeString();
        $values['company_uuid'] = session('company');
        $values['_key']         = session('api_key', 'console');

        if (isset($values['location'])) {
            $values['location'] = Utils::parsePointToWkt($values['location']);
        }

        // check if place already exists
        $existing = DB::table($instance->getTable())
            ->select(['uuid'])->where([
                'company_uuid' => session('company'),
                'name'         => $values['name'] ?? null,
                'street1'      => $values['street1'] ?? null,
            ])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            unset($values['uuid'], $values['created_at'], $values['_key'], $values['company_uuid']);
            static::where('uuid', $existing->uuid)->update($values);

            return $existing->uuid;
        }

        if (isset($values['meta']) && (is_object($values['meta']) || is_array($values['meta']))) {
            $values['meta'] = json_encode($values['meta']);
        }

        $result = static::insert($values);

        return $result ? $uuid : false;
    }

    /**
     * Create a new Place instance from an import row.
     *
     * @param array       $row      the import row to create a Place from
     * @param int         $importId the ID of the import the row is associated with
     * @param string|null $country  an optional country to append to the address if it doesn't already contain it
     *
     * @return Place|null the newly created Place instance, or null if no valid address could be found
     */
    public static function createFromImportRow($row, $importId, $country = null): ?Place
    {
        $addressFields = [
            'street_number' => ['alias' => ['number', 'house_number', 'st_number']],
            'street2'       => ['alias' => ['unit', 'unit_number']],
            'city'          => ['alias' => ['town']],
            'neighborhood'  => ['alias' => ['district']],
            'province'      => ['alias' => ['state']],
            'postal_code'   => ['alias' => ['postal', 'zip', 'zip_code']],
            'phone'         => ['alias' => ['phone', 'mobile', 'phone_number', 'number', 'cell', 'cell_phone', 'mobile_number', 'contact_number']],
        ];
        $address = '';

        foreach ($addressFields as $field => $options) {
            if ($field === 'phone') {
                continue;
            }
            $value = Utils::or($row, $options['alias']);
            if ($value) {
                $address .= $value . ' ';
            }
        }

        $address = rtrim($address);

        if (!$address) {
            return null;
        }

        $place = Place::createFromGeocodingLookup($address, false);

        foreach ($addressFields as $field => $options) {
            if (empty($place->{$field})) {
                $value = Utils::or($row, $options['alias']);
                if ($value) {
                    $place->{$field} = $value;
                }
            }
        }

        if ($country && !Str::contains($address, $country)) {
            $address .= ' ' . $country;
        }

        // set the phone number if found
        $place->phone = Utils::or($row, $addressFields['phone']['alias']);

        // set meta data
        $meta = collect($row)->except(['name', ...$addressFields['street_number']['alias'], ...$addressFields['street2']['alias'], ...$addressFields['city']['alias'], ...$addressFields['neighborhood']['alias'], ...$addressFields['province']['alias'], ...$addressFields['postal_code']['alias'], ...$addressFields['phone']['alias']])->toArray();
        $place->setMetas($meta);

        // set the import id
        $place->setAttribute('_import_id', $importId);

        return $place;
    }

    /**
     * Returns a formatted string representation of the address for this Place instance.
     *
     * @param array $except  an optional array of address components to exclude from the returned string
     * @param bool  $useHtml whether to format the returned string as HTML
     *
     * @return string the formatted address string
     */
    public function toAddressString($except = [], $useHtml = false)
    {
        return Utils::getAddressStringForPlace($this, $useHtml, $except);
    }

    /**
     * Get the full place address as a string.
     *
     * @param bool $useHtml whether to use HTML formatting for the address string
     *
     * @return string the full address as a string
     */
    public function getAddressString($useHtml = false)
    {
        return $this->toAddressString($useHtml);
    }

    /**
     * Get the vendor's address as an HTML string.
     *
     * @return string the vendor's address as an HTML string
     */
    public function getAddressHtmlAttribute()
    {
        return $this->getAddressString(true);
    }

    /**
     * Get the vendor's address as a string.
     *
     * @return string the vendor's address as a string
     */
    public function getAddressAttribute()
    {
        return $this->getAddressString();
    }
}
