<?php

namespace Fleetbase\FleetOps\Models;

use Brick\Geo\IO\GeoJSONReader;
use Fleetbase\Casts\Money;
use Fleetbase\FleetOps\Support\Algo;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;

class ServiceRate extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use SendsWebhooks;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'service_rates';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'service';

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
        'company_uuid',
        'service_area_uuid',
        'zone_uuid',
        'order_config_uuid',
        'service_name',
        'service_type',
        'per_meter_flat_rate_fee',
        'per_meter_unit',
        'base_fee',
        'algorithm',
        'max_distance_unit',
        'max_distance',
        'rate_calculation_method',
        'has_cod_fee',
        'cod_calculation_method',
        'cod_flat_fee',
        'cod_percent',
        'has_peak_hours_fee',
        'peak_hours_calculation_method',
        'peak_hours_flat_fee',
        'peak_hours_percent',
        'peak_hours_start',
        'peak_hours_end',
        'currency',
        'duration_terms',
        'estimated_days',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'base_fee'                => Money::class,
        'per_meter_flat_rate_fee' => Money::class,
        'cod_flat_fee'            => Money::class,
        'peak_hours_flat_fee'     => Money::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['service_area_name', 'zone_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['serviceArea', 'zone'];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function rateFees()
    {
        return $this->hasMany(ServiceRateFee::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function parcelFees()
    {
        return $this->hasMany(ServiceRateParcelFee::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function orderConfig()
    {
        return $this->belongsTo(OrderConfig::class)->withTrashed();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function serviceArea()
    {
        return $this->belongsTo(ServiceArea::class)->whereNull('deleted_at');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Get the service area name attribute.
     */
    public function getServiceAreaNameAttribute(): ?string
    {
        return data_get($this, 'serviceArea.name');
    }

    /**
     * Get the zone name attribute.
     */
    public function getZoneNameAttribute(): ?string
    {
        return data_get($this, 'zone.name');
    }

    /**
     * Set the number of estimated days for the service to complete.
     *
     * @param int $estimatedDays
     *
     * @return void
     */
    public function setEstimatedDaysAttribute($estimatedDays = 0)
    {
        $this->attributes['estimated_days'] = $estimatedDays ?? 0;
    }

    /**
     * Check if the rate calculation method matches the given method.
     *
     * @param string $method
     */
    public function isRateCalculationMethod(string|array $method): bool
    {
        if (is_array($method)) {
            return in_array($this->rate_calculation_method, $method);
        }

        return $this->rate_calculation_method === $method;
    }

    /**
     * Check if the rate calculation method is "fixed_meter" or "fixed_rate".
     */
    public function isFixedMeter(): bool
    {
        return $this->rate_calculation_method === 'fixed_meter' || $this->rate_calculation_method === 'fixed_rate';
    }

    /**
     * Check if the rate calculation method is "fixed_meter" or "fixed_rate".
     */
    public function isFixedRate(): bool
    {
        return $this->rate_calculation_method === 'fixed_meter' || $this->rate_calculation_method === 'fixed_rate';
    }

    /**
     * Check if the rate calculation method is "per_meter".
     */
    public function isPerMeter(): bool
    {
        return $this->rate_calculation_method === 'per_meter';
    }

    /**
     * Check if the rate calculation method is "multi_zone_distance".
     */
    public function isMultiZoneDistance(): bool
    {
        return $this->rate_calculation_method === 'multi_zone_distance';
    }

    /**
     * Check if the rate calculation method is "per_drop".
     */
    public function isPerDrop(): bool
    {
        return $this->rate_calculation_method === 'per_drop';
    }

    /**
     * Check if the rate calculation method is "algo".
     */
    public function isAlgorithm(): bool
    {
        return $this->rate_calculation_method === 'algo' || $this->rate_calculation_method === 'algorithm';
    }

    /**
     * Check if the service type is "parcel".
     */
    public function isParcelService(): bool
    {
        return $this->rate_calculation_method === 'parcel';
    }

    /**
     * Check if the object has a peak hours fee.
     */
    public function hasPeakHoursFee(): bool
    {
        return (bool) $this->has_peak_hours_fee;
    }

    /**
     * Check if the current time is within peak hours.
     */
    public function isWithinPeakHours(): bool
    {
        $currentTime = strtotime(date('H:i'));
        $startTime   = strtotime($this->peak_hours_start);
        $endTime     = strtotime($this->peak_hours_end);

        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    /**
     * Check if the peak hours calculation method is "flat".
     */
    public function hasPeakHoursFlatFee(): bool
    {
        return $this->peak_hours_calculation_method === 'flat';
    }

    /**
     * Check if the peak hours calculation method is "percentage".
     */
    public function hasPeakHoursPercentageFee(): bool
    {
        return $this->peak_hours_calculation_method === 'percentage';
    }

    /**
     * Check if the object has a COD fee.
     */
    public function hasCodFee(): bool
    {
        return (bool) $this->has_cod_fee;
    }

    /**
     * Check if the COD calculation method is "flat".
     */
    public function hasCodFlatFee(): bool
    {
        return $this->cod_calculation_method === 'flat';
    }

    /**
     * Check if the COD calculation method is "percentage".
     */
    public function hasCodPercentageFee(): bool
    {
        return $this->cod_calculation_method === 'percentage';
    }

    /**
     * Check if the object has a related zone.
     */
    public function hasZone(): bool
    {
        return $this->loadMissing('zone')->zone instanceof Zone;
    }

    /**
     * Check if the object has a related service area.
     */
    public function hasServiceArea(): bool
    {
        return $this->loadMissing('serviceArea')->serviceArea instanceof ServiceArea;
    }

    /**
     * Set the service rate fees for the current object.
     *
     * @param array|null $serviceRateFees an optional array of service rate fees
     *
     * @return $this
     */
    public function setServiceRateFees(?array $serviceRateFees = [])
    {
        if (!$serviceRateFees) {
            return $this;
        }

        $serviceRateFees = collect($serviceRateFees)
            ->map(fn ($fee) => $this->normalizeServiceRateFeePayload($fee))
            ->filter()
            ->values()
            ->toArray();

        if (!$serviceRateFees) {
            return $this;
        }

        $iterate = count($serviceRateFees);

        for ($i = 0; $i < $iterate; $i++) {
            // if already has uuid then we just update the record and remove from insert array
            if (!empty($serviceRateFees[$i]['uuid'])) {
                $id                   = $serviceRateFees[$i]['uuid'];
                $updateableAttributes = collect($serviceRateFees[$i])->except(['uuid', 'created_at', 'updated_at'])->toArray();

                if ($updateableAttributes) {
                    // Apply casts before update
                    $updateableAttributes = ServiceRateFee::onRowInsert($updateableAttributes);
                    ServiceRateFee::where('uuid', $id)->update($updateableAttributes);
                }

                unset($serviceRateFees[$i]);
                continue;
            }

            $serviceRateFees[$i]['service_rate_uuid'] = $this->uuid;
        }

        $serviceRateFees = collect($serviceRateFees)->filter()->values()->toArray();
        ServiceRateFee::bulkInsert($serviceRateFees);

        return $this;
    }

    /**
     * Normalize embedded service-rate fee payloads before persistence.
     */
    public function normalizeServiceRateFeePayload($fee): ?array
    {
        if (!is_array($fee)) {
            return null;
        }

        $fee['service_area_uuid'] ??= data_get($fee, 'service_area.uuid');
        $fee['zone_uuid'] ??= data_get($fee, 'zone.uuid');

        if (Utils::castBoolean($fee['is_fallback'] ?? false)) {
            $fee['service_area_uuid'] = null;
            $fee['zone_uuid']         = null;
        }

        return collect($fee)
            ->only([
                'uuid',
                'service_rate_uuid',
                'service_area_uuid',
                'zone_uuid',
                'label',
                'priority',
                'is_fallback',
                'distance',
                'distance_unit',
                'min',
                'max',
                'unit',
                'fee',
                'currency',
            ])
            ->toArray();
    }

    /**
     * Set the service rate parcel fees for the current object.
     *
     * @param array|null $serviceRateParcelFees an optional array of service rate parcel fees
     *
     * @return $this
     */
    public function setServiceRateParcelFees(?array $serviceRateParcelFees = [])
    {
        if (!$serviceRateParcelFees) {
            return $this;
        }

        // Normalize duplicate parcel rows by fee shape and keep the latest submitted value.
        $serviceRateParcelFees = collect($serviceRateParcelFees)
            ->filter(fn ($fee) => is_array($fee))
            ->map(function ($fee) {
                return collect($fee)->except(['created_at', 'updated_at'])->toArray();
            })
            ->keyBy(function ($fee) {
                return implode(':', [
                    data_get($fee, 'size'),
                    data_get($fee, 'length'),
                    data_get($fee, 'width'),
                    data_get($fee, 'height'),
                    data_get($fee, 'dimensions_unit'),
                    data_get($fee, 'weight'),
                    data_get($fee, 'weight_unit'),
                ]);
            })
            ->values()
            ->toArray();

        $submittedUuids = collect($serviceRateParcelFees)
            ->pluck('uuid')
            ->filter()
            ->values()
            ->all();

        $iterate = count($serviceRateParcelFees);

        for ($i = 0; $i < $iterate; $i++) {
            // if already has uuid then we just update the record and remove from insert array
            if (!empty($serviceRateParcelFees[$i]['uuid'])) {
                $id                   = $serviceRateParcelFees[$i]['uuid'];
                $updateableAttributes = collect($serviceRateParcelFees[$i])->except(['uuid', 'created_at', 'updated_at'])->toArray();

                if ($updateableAttributes) {
                    // Apply casts before update
                    $updateableAttributes = ServiceRateParcelFee::onRowInsert($updateableAttributes);
                    ServiceRateParcelFee::where('uuid', $id)->update($updateableAttributes);
                }

                unset($serviceRateParcelFees[$i]);
                continue;
            }

            $serviceRateParcelFees[$i]['service_rate_uuid'] = $this->uuid;
        }

        $serviceRateParcelFees = collect($serviceRateParcelFees)->filter()->values()->toArray();

        $existingParcelFeesQuery = ServiceRateParcelFee::where('service_rate_uuid', $this->uuid);

        if (!empty($submittedUuids)) {
            $existingParcelFeesQuery->whereNotIn('uuid', $submittedUuids)->delete();
        } else {
            $existingParcelFeesQuery->delete();
        }

        if (!empty($serviceRateParcelFees)) {
            ServiceRateParcelFee::bulkInsert($serviceRateParcelFees);
        }

        return $this;
    }

    /**
     * Get the service rates applicable for the given waypoints.
     *
     * @param array         $waypoints     an array of waypoints to check against service areas and zones
     * @param \Closure|null $queryCallback an optional closure to modify the service rates query
     *
     * @return array an array of applicable service rates
     */
    public static function getServicableForWaypoints($waypoints = [], ?\Closure $queryCallback = null): array
    {
        $reader                 = new GeoJSONReader();
        $applicableServiceRates = [];
        $serviceRatesQuery      = static::with(['zone', 'serviceArea']);

        if (is_callable($queryCallback)) {
            $queryCallback($serviceRatesQuery);
        }

        // get service rates
        $serviceRates = $serviceRatesQuery->get();

        foreach ($serviceRates as $serviceRate) {
            if ($serviceRate->hasServiceArea()) {
                if (Utils::exists($serviceRate, 'serviceArea.border')) {
                    // make sure all waypoints fall within the service area
                    foreach ($serviceRate->serviceArea->border as $polygon) {
                        $polygon = $reader->read($polygon->toJson());

                        foreach ($waypoints as $waypoint) {
                            if (!$polygon->contains($waypoint)) {
                                // waypoint outside of service area, not applicable to route
                                continue;
                            }
                        }
                    }
                }
            }

            if ($serviceRate->hasZone()) {
                // make sure all waypoints fall within the service area
                if (Utils::exists($serviceRate, 'zone.border')) {
                    foreach ($serviceRate->zone->border as $polygon) {
                        $polygon = $reader->read($polygon->toJson());

                        foreach ($waypoints as $waypoint) {
                            if (!$polygon->contains($waypoint)) {
                                // waypoint outside of zone, not applicable to route
                                continue;
                            }
                        }
                    }
                }
            }

            $applicableServiceRates[] = $serviceRate;
        }

        return $applicableServiceRates;
    }

    /**
     * Get the service rates applicable for the given places based on service type and currency.
     *
     * @param array         $places        an array of places to check against service areas and zones
     * @param string|null   $service       an optional service type to filter service rates
     * @param string|null   $currency      an optional currency to filter service rates
     * @param \Closure|null $queryCallback an optional closure to modify the service rates query
     *
     * @return array an array of applicable service rates
     */
    public static function getServicableForPlaces($places = [], $service = null, $currency = null, ?\Closure $queryCallback = null): array
    {
        $reader            = new GeoJSONReader();
        $serviceRatesQuery = static::with(['zone', 'serviceArea', 'rateFees.zone', 'rateFees.serviceArea', 'parcelFees']);

        if ($currency) {
            $serviceRatesQuery->whereRaw('lower(currency) = ?', [strtolower($currency)]);
        }

        if ($service) {
            $serviceRatesQuery->where('service_type', $service);
        }

        if (is_callable($queryCallback)) {
            $queryCallback($serviceRatesQuery);
        }

        $serviceRates = $serviceRatesQuery->get();

        $waypoints = collect($places)
            ->map(function ($place) {
                $place = Place::createFromMixed($place);

                if (!$place instanceof Place) {
                    return null;
                }

                $point = $place->getLocationAsPoint();

                // Brick point: X=lng, Y=lat (WKT order)
                return \Brick\Geo\Point::fromText(
                    sprintf('POINT (%F %F)', $point->getLng(), $point->getLat()),
                    4326
                );
            })
            ->filter()
            ->values();

        if ($waypoints->isEmpty()) {
            return [];
        }

        /**
         * Convert a casted spatial geometry (Zone::border / ServiceArea::border)
         * into a Brick geometry using GeoJSONReader.
         */
        $toBrickGeometry = function ($spatialGeometry) use ($reader) {
            if (!$spatialGeometry) {
                return null;
            }

            // Most Fleetbase spatial casts/types implement toJson()
            if (is_object($spatialGeometry) && method_exists($spatialGeometry, 'toJson')) {
                $json = $spatialGeometry->toJson();
                $json = is_string($json) ? trim($json) : null;

                if ($json) {
                    return $reader->read($json);
                }

                return null;
            }

            // Fallback if the cast ever returns array/object
            if (is_array($spatialGeometry) || is_object($spatialGeometry)) {
                $json = json_encode($spatialGeometry, JSON_UNESCAPED_UNICODE);
                if ($json && $json !== 'null') {
                    return $reader->read($json);
                }
            }

            // Fallback if it’s a raw JSON string
            if (is_string($spatialGeometry) && trim($spatialGeometry) !== '') {
                return $reader->read($spatialGeometry);
            }

            return null;
        };

        /**
         * Ensure ALL waypoints are inside the given Brick geometry.
         */
        $containsAllWaypoints = function ($brickGeometry) use ($waypoints): bool {
            if (!$brickGeometry) {
                return false;
            }

            foreach ($waypoints as $waypoint) {
                if (!$brickGeometry->contains($waypoint)) {
                    return false;
                }
            }

            return true;
        };

        $applicableServiceRates = [];

        foreach ($serviceRates as $serviceRate) {
            // If a service area exists, all waypoints must be inside its border
            if ($serviceRate->hasServiceArea()) {
                $serviceAreaBorder = $serviceRate->serviceArea?->border;

                $serviceAreaGeom = null;
                try {
                    $serviceAreaGeom = $toBrickGeometry($serviceAreaBorder);
                } catch (\Throwable $e) {
                    continue; // invalid geojson / geometry -> reject this rate
                }

                if (!$containsAllWaypoints($serviceAreaGeom)) {
                    continue;
                }
            }

            // If a zone exists, all waypoints must be inside its border
            if ($serviceRate->hasZone()) {
                $zoneBorder = $serviceRate->zone?->border;

                $zoneGeom = null;
                try {
                    $zoneGeom = $toBrickGeometry($zoneBorder);
                } catch (\Throwable $e) {
                    continue;
                }

                if (!$containsAllWaypoints($zoneGeom)) {
                    continue;
                }
            }

            $applicableServiceRates[] = $serviceRate;
        }

        return $applicableServiceRates;
    }

    /**
     * Generate a quote for a given pickup and dropoff point and entities.
     *
     * @param string $pickupPoint  the coordinates of the pickup point
     * @param string $dropoffPoint the coordinates of the dropoff point
     * @param array  $entities     an array of entities to be considered for the quote
     *
     * @return mixed the calculated quote based on the preliminary data
     */
    public function pointQuote($pickupPoint, $dropoffPoint, $entities = [])
    {
        $payload           = new Payload();
        $payload->entities = $entities;
        $payload->pickup   = $pickup = new Place([
            'location' => Utils::getPointFromCoordinates($pickupPoint),
        ]);
        $payload->dropoff = $dropoff = new Place([
            'location' => Utils::getPointFromCoordinates($dropoffPoint),
        ]);

        // calculate distance and time
        $matrix = Utils::getDrivingDistanceAndTime($payload->pickup, $payload->dropoff);

        return $this->quoteFromPreliminaryData($entities, [$pickup, $dropoff], $matrix->distance, $matrix->time);
    }

    /**
     * Generate a quote based on the preliminary data provided.
     *
     * @param array     $entities         an array of entities to be considered for the quote
     * @param array     $waypoints        an array of waypoints to be considered for the quote
     * @param int|null  $totalDistance    the total distance for the service in meters
     * @param int|null  $totalTime        the total time for the service in seconds
     * @param bool|null $isCashOnDelivery flag indicating if the payment method is Cash on Delivery
     *
     * @return array an array containing the calculated quote and line items
     */
    public function quoteFromPreliminaryData($entities = [], $waypoints = [], ?int $totalDistance = 0, ?int $totalTime = 0, ?bool $isCashOnDelivery = false, ?int $endpointCount = null)
    {
        $lines    = collect();
        $subTotal = data_get($this, 'base_fee', 0);

        $lines->push([
            'details'          => 'Base Fee',
            'raw_amount'       => $subTotal,
            'amount'           => Utils::numbersOnly($subTotal),
            'formatted_amount' => Utils::moneyFormat($subTotal, $this->currency),
            'currency'         => $this->currency,
            'code'             => 'BASE_FEE',
        ]);

        if ($this->isFixedMeter()) {
            $distanceFee = $this->findServiceRateFeeByDistance($totalDistance);

            if ($distanceFee) {
                $subTotal += Utils::numbersOnly($distanceFee->fee);

                $lines->push([
                    'details'          => 'Service Fee',
                    'raw_amount'       => $distanceFee->fee,
                    'amount'           => Utils::numbersOnly($distanceFee->fee),
                    'formatted_amount' => Utils::moneyFormat($distanceFee->fee, $this->currency),
                    'currency'         => $this->currency,
                    'code'             => 'BASE_FEE',
                ]);
            }
        }

        if ($this->isPerDrop()) {
            $rateFee = $this->findServiceRateFeeByMinMax(count($waypoints));

            if ($rateFee) {
                $subTotal += Utils::numbersOnly($rateFee->fee);

                $lines->push([
                    'details'          => 'Service Fee',
                    'amount'           => Utils::numbersOnly($rateFee->fee),
                    'formatted_amount' => Utils::moneyFormat($rateFee->fee, $this->currency),
                    'currency'         => $this->currency,
                    'code'             => 'BASE_FEE',
                ]);
            }
        }

        if ($this->isPerMeter()) {
            $perMeterDistance = $this->normalizeDistanceForUnit($totalDistance, $this->per_meter_unit);
            $rateFee          = $this->normalizeCalculatedMoney($perMeterDistance * $this->per_meter_flat_rate_fee);
            $subTotal += $rateFee;

            $lines->push([
                'details'          => 'Service Fee',
                'amount'           => $rateFee,
                'formatted_amount' => Utils::moneyFormat($rateFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'BASE_FEE',
            ]);
        }

        if ($this->isMultiZoneDistance()) {
            [$multiZoneFee, $multiZoneLines] = $this->quoteMultiZoneDistance($waypoints, $totalDistance);
            $subTotal += $multiZoneFee;
            $lines = $lines->merge($multiZoneLines);
        }

        if ($this->isAlgorithm()) {
            $resolvedEndpointCount = $endpointCount ?? $this->inferEndpointCountFromStops($waypoints);
            $rateFee               = $this->normalizeCalculatedMoney(Algo::exec(
                $this->algorithm,
                $this->buildAlgorithmVariables(
                    $entities,
                    $waypoints,
                    $totalDistance,
                    $totalTime,
                    $resolvedEndpointCount
                ),
                true
            ));

            $subTotal += $rateFee;

            $lines->push([
                'details'          => 'Service Fee',
                'amount'           => $rateFee,
                'formatted_amount' => Utils::moneyFormat($rateFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'BASE_FEE',
            ]);
        }

        // if parcel fee's add into the base rate
        if ($this->isParcelService()) {
            $parcels = collect($entities)->where('type', 'parcel')->all();

            foreach ($parcels as $parcel) {
                // convert all length units to cm and weight units to grams
                $length           = $parcel->length_unit->toUnit('cm');
                $width            = $parcel->width_unit->toUnit('cm');
                $height           = $parcel->height_unit->toUnit('cm');
                $weight           = $parcel->mass_unit->toUnit('g');
                $serviceParcelFee = null;

                // iterate through parcel fees to find where it fits
                foreach ($this->parcelFees as $parcelFee) {
                    $feeLength = $parcelFee->length_unit->toUnit('cm');
                    $feeWidth  = $parcelFee->width_unit->toUnit('cm');
                    $feeHeight = $parcelFee->height_unit->toUnit('cm');
                    $feeWeight = $parcelFee->mass_unit->toUnit('g');

                    $previousParcelFee = $parcelFee;

                    if ($length > $feeLength && $width > $feeWidth && $height > $feeHeight && $weight > $feeWeight) {
                        continue;
                    } elseif ($length < $feeLength && $width < $feeWidth && $height < $feeHeight && $weight < $feeWeight) {
                        $serviceParcelFee = $previousParcelFee;
                    } else {
                        $serviceParcelFee = $parcelFee;
                    }
                }

                // if no distance fee use the last
                if ($serviceParcelFee === null) {
                    $serviceParcelFee = $this->parcelFees->sortByDesc()->first();
                }

                $subTotal += $serviceParcelFee->fee;
                $parcelFeeName = ucwords(str_replace(['_', '-'], ' ', data_get($serviceParcelFee, 'size', 'parcel')));

                $lines->push([
                    'details'          => $parcelFeeName . ' parcel fee',
                    'amount'           => Utils::numbersOnly($serviceParcelFee->fee),
                    'formatted_amount' => Utils::moneyFormat($serviceParcelFee->fee, $this->currency),
                    'currency'         => $this->currency,
                    'code'             => 'PARCEL_FEE',
                ]);
            }
        }

        // set the base rate
        $baseRate = $subTotal;

        // if the rate has cod add this into the quote price
        if ($this->hasCodFee() && $isCashOnDelivery) {
            if ($this->hasCodFlatFee()) {
                $subTotal += $codFee = $this->cod_flat_fee;
            } elseif ($this->hasCodPercentageFee()) {
                $subTotal += $codFee = $this->normalizeCalculatedMoney(Utils::calculatePercentage($this->cod_percent, $baseRate));
            }

            $lines->push([
                'details'          => 'Cash on delivery fee',
                'amount'           => Utils::numbersOnly($codFee),
                'formatted_amount' => Utils::moneyFormat($codFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'COD_FEE',
            ]);
        }

        // if this has peak hour fee add in
        if ($this->hasPeakHoursFee() && $this->isWithinPeakHours()) {
            if ($this->hasPeakHoursFlatFee()) {
                $subTotal += $peakHoursFee = $this->peak_hours_flat_fee;
            } elseif ($this->hasPeakHoursPercentageFee()) {
                $subTotal += $peakHoursFee = $this->normalizeCalculatedMoney(Utils::calculatePercentage($this->peak_hours_percent, $baseRate));
            }

            $lines->push([
                'details'          => 'Peak hours fee',
                'amount'           => Utils::numbersOnly($peakHoursFee),
                'formatted_amount' => Utils::moneyFormat($peakHoursFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'PEAK_HOUR_FEE',
            ]);
        }

        return [$subTotal, $lines];
    }

    /**
     * Generate a quote based on the payload provided.
     *
     * @param Payload $payload an instance of the Payload class containing all necessary data for the quote calculation
     *
     * @return array an array containing the calculated quote and line items
     */
    public function quote(Payload $payload)
    {
        $lines    = collect();
        $subTotal = $this->base_fee ?? 0;

        $lines->push([
            'details'          => 'Base Fee',
            'amount'           => Utils::numbersOnly($subTotal),
            'formatted_amount' => Utils::moneyFormat($subTotal, $this->currency),
            'currency'         => $this->currency,
            'code'             => 'BASE_FEE',
        ]);

        // Prepare all waypoints and origin and destination
        $waypoints    = $payload->getAllStops();
        $origin       = $waypoints->first();
        $destinations = $waypoints->skip(1)->toArray();

        // Lookup distance matrix for total distance and time
        $distanceMatrix = Utils::distanceMatrix([$origin], $destinations);
        $totalDistance  = $distanceMatrix->distance;
        $totalTime      = $distanceMatrix->time;

        if ($this->isFixedMeter()) {
            $distanceFee = $this->findServiceRateFeeByDistance($totalDistance);

            if ($distanceFee) {
                $subTotal += Utils::numbersOnly($distanceFee->fee);

                $lines->push([
                    'details'          => 'Service Fee',
                    'amount'           => Utils::numbersOnly($distanceFee->fee),
                    'formatted_amount' => Utils::moneyFormat($distanceFee->fee, $this->currency),
                    'currency'         => $this->currency,
                    'code'             => 'BASE_FEE',
                ]);
            }
        }

        if ($this->isPerDrop()) {
            $rateFee = $this->findServiceRateFeeByMinMax(count($waypoints));

            if ($rateFee) {
                $subTotal += Utils::numbersOnly($rateFee->fee);

                $lines->push([
                    'details'          => 'Service Fee',
                    'amount'           => Utils::numbersOnly($rateFee->fee),
                    'formatted_amount' => Utils::moneyFormat($rateFee->fee, $this->currency),
                    'currency'         => $this->currency,
                    'code'             => 'BASE_FEE',
                ]);
            }
        }

        if ($this->isPerMeter()) {
            $perMeterDistance = $this->normalizeDistanceForUnit($totalDistance, $this->per_meter_unit);
            $rateFee          = $this->normalizeCalculatedMoney($perMeterDistance * $this->per_meter_flat_rate_fee);
            $subTotal += $rateFee;

            $lines->push([
                'details'          => 'Service Fee',
                'amount'           => $rateFee,
                'formatted_amount' => Utils::moneyFormat($rateFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'BASE_FEE',
            ]);
        }

        if ($this->isMultiZoneDistance()) {
            [$multiZoneFee, $multiZoneLines] = $this->quoteMultiZoneDistance($waypoints, $totalDistance);
            $subTotal += $multiZoneFee;
            $lines = $lines->merge($multiZoneLines);
        }

        if ($this->isAlgorithm()) {
            $rateFee = $this->normalizeCalculatedMoney(Algo::exec(
                $this->algorithm,
                $this->buildAlgorithmVariables(
                    $payload->entities->all(),
                    $waypoints->all(),
                    $totalDistance,
                    $totalTime,
                    (int) ($payload->pickup ? 1 : 0) + (int) ($payload->dropoff ? 1 : 0)
                ),
                true
            ));

            $subTotal += $rateFee;

            $lines->push([
                'details'          => 'Service Fee',
                'amount'           => $rateFee,
                'formatted_amount' => Utils::moneyFormat($rateFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'BASE_FEE',
            ]);
        }

        // if parcel fee's add into the base rate
        if ($this->isParcelService()) {
            $parcels = $payload->entities->where('type', 'parcel')->all();

            foreach ($parcels as $parcel) {
                // convert all length units to cm and weight units to grams
                $length           = $parcel->length_unit->toUnit('cm');
                $width            = $parcel->width_unit->toUnit('cm');
                $height           = $parcel->height_unit->toUnit('cm');
                $weight           = $parcel->mass_unit->toUnit('g');
                $serviceParcelFee = null;

                // iterate through parcel fees to find where it fits
                foreach ($this->parcelFees as $parcelFee) {
                    $feeLength = $parcelFee->length_unit->toUnit('cm');
                    $feeWidth  = $parcelFee->width_unit->toUnit('cm');
                    $feeHeight = $parcelFee->height_unit->toUnit('cm');
                    $feeWeight = $parcelFee->mass_unit->toUnit('g');

                    $previousParcelFee = $parcelFee;

                    if ($length > $feeLength && $width > $feeWidth && $height > $feeHeight && $weight > $feeWeight) {
                        continue;
                    } elseif ($length < $feeLength && $width < $feeWidth && $height < $feeHeight && $weight < $feeWeight) {
                        $serviceParcelFee = $previousParcelFee;
                    } else {
                        $serviceParcelFee = $parcelFee;
                    }
                }

                // if no distance fee use the last
                if ($serviceParcelFee === null) {
                    $serviceParcelFee = $this->parcelFees->sortByDesc()->first();
                }

                $subTotal += $serviceParcelFee->fee;
                $parcelFeeName = ucwords(str_replace(['_', '-'], ' ', data_get($serviceParcelFee, 'size', 'parcel')));

                $lines->push([
                    'details'          => $parcelFeeName . ' parcel fee',
                    'amount'           => Utils::numbersOnly($serviceParcelFee->fee),
                    'formatted_amount' => Utils::moneyFormat($serviceParcelFee->fee, $this->currency),
                    'currency'         => $this->currency,
                    'code'             => 'PARCEL_FEE',
                ]);
            }
        }

        // set the base rate
        $baseRate = $subTotal;

        // if the rate has cod add this into the quote price
        if ($this->hasCodFee() && $payload->cod_amount !== null) {
            if ($this->hasCodFlatFee()) {
                $subTotal += $codFee = $this->cod_flat_fee;
            } elseif ($this->hasCodPercentageFee()) {
                $subTotal += $codFee = $this->normalizeCalculatedMoney(Utils::calculatePercentage($this->cod_percent, $baseRate));
            }

            $lines->push([
                'details'          => 'Cash on delivery fee',
                'amount'           => Utils::numbersOnly($codFee),
                'formatted_amount' => Utils::moneyFormat($codFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'COD_FEE',
            ]);
        }

        // if this has peak hour fee add in
        if ($this->hasPeakHoursFee() && $this->isWithinPeakHours()) {
            if ($this->hasPeakHoursFlatFee()) {
                $subTotal += $peakHoursFee = $this->peak_hours_flat_fee;
            } elseif ($this->hasPeakHoursPercentageFee()) {
                $subTotal += $peakHoursFee = $this->normalizeCalculatedMoney(Utils::calculatePercentage($this->peak_hours_percent, $baseRate));
            }

            $lines->push([
                'details'          => 'Peak hours fee',
                'amount'           => Utils::numbersOnly($peakHoursFee),
                'formatted_amount' => Utils::moneyFormat($peakHoursFee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'PEAK_HOUR_FEE',
            ]);
        }

        return [$subTotal, $lines];
    }

    protected function normalizeDistanceForUnit(?int $distanceInMeters = 0, ?string $unit = 'm'): float|int
    {
        $distanceInMeters = (float) ($distanceInMeters ?? 0);

        return match ($unit) {
            'km'    => $distanceInMeters / 1000,
            'ft'    => $distanceInMeters / 0.3048,
            'yd'    => $distanceInMeters / 0.9144,
            'mi'    => $distanceInMeters / 1609.344,
            default => $distanceInMeters,
        };
    }

    protected function normalizeCalculatedMoney($amount = 0): int
    {
        return (int) round((float) ($amount ?? 0));
    }

    protected function buildAlgorithmVariables($entities = [], $stops = [], ?int $totalDistance = 0, ?int $totalTime = 0, int $endpointCount = 0): array
    {
        $entityCollection = collect($entities)->filter();
        $stopCount        = collect($stops)->filter()->count();
        $endpointCount    = max(0, min($endpointCount, $stopCount));
        $waypointCount    = max($stopCount - $endpointCount, 0);
        $weightVariables  = $this->buildAlgorithmWeightVariables($entityCollection);

        return Algo::normalizeVariables(array_merge([
            'distance_m' => $totalDistance ?? 0,
            'time_s'     => $totalTime ?? 0,
            'stops'      => $stopCount,
            'waypoints'  => $waypointCount,
            'parcels'    => $entityCollection->where('type', 'parcel')->count(),
            'entities'   => $entityCollection->count(),
            'base_fee'   => Utils::numbersOnly($this->base_fee ?? 0),
        ], $weightVariables));
    }

    protected function buildAlgorithmWeightVariables($entities): array
    {
        $weightKg = collect($entities)->sum(fn ($entity) => $this->normalizeEntityWeightToKilograms($entity));

        return [
            'weight'       => $weightKg,
            'weight_kg'    => $weightKg,
            'weight_g'     => $weightKg * 1000,
            'weight_lb'    => $weightKg / 0.45359237,
            'weight_oz'    => $weightKg / 0.028349523125,
            'weight_tonne' => $weightKg / 1000,
            'weight_t'     => $weightKg / 1000,
        ];
    }

    protected function normalizeEntityWeightToKilograms($entity): float
    {
        $weight = data_get($entity, 'weight');

        if ($weight === null || $weight === '' || !is_numeric($weight)) {
            return 0;
        }

        $weight = (float) $weight;
        $unit   = strtolower(trim((string) data_get($entity, 'weight_unit', 'kg')));

        return match ($unit) {
            'g', 'gram', 'grams'                       => $weight / 1000,
            'oz', 'ounce', 'ounces'                    => $weight * 0.028349523125,
            'lb', 'lbs', 'pound', 'pounds'             => $weight * 0.45359237,
            't', 'mt', 'tonne', 'tonnes', 'metric_ton',
            'metric_tons'                              => $weight * 1000,
            default                                    => $weight,
        };
    }

    protected function inferEndpointCountFromStops($stops = []): int
    {
        $stopCount = collect($stops)->filter()->count();

        if ($stopCount <= 1) {
            return $stopCount;
        }

        return 2;
    }

    protected function quoteMultiZoneDistance($waypoints = [], ?int $totalDistance = 0): array
    {
        $this->loadMissing(['rateFees.zone', 'rateFees.serviceArea']);

        $rules = $this->rateFees
            ->filter(fn ($rule) => !$rule->is_fallback && ($rule->zone || $rule->serviceArea))
            ->sortByDesc(fn ($rule) => (int) ($rule->priority ?? 0))
            ->values();

        $fallbackRule = $this->rateFees
            ->filter(fn ($rule) => (bool) $rule->is_fallback)
            ->sortByDesc(fn ($rule) => (int) ($rule->priority ?? 0))
            ->first();

        if ($rules->isEmpty() && !$fallbackRule) {
            return [0, collect()];
        }

        $pricedDistances = $this->calculateMultiZoneDistances($waypoints, $rules, $fallbackRule, $totalDistance);
        $lines           = collect();
        $subTotal        = 0;

        foreach ($pricedDistances as $entry) {
            $rule = $entry['rule'];
            if (!$rule instanceof ServiceRateFee) {
                continue;
            }

            $distanceInMeters = (float) ($entry['distance_m'] ?? 0);
            if ($distanceInMeters <= 0) {
                continue;
            }

            $unit     = strtolower($rule->distance_unit ?: 'km');
            $distance = $this->normalizeDistanceForUnit($distanceInMeters, $unit);
            $fee      = $this->normalizeCalculatedMoney($distance * Utils::numbersOnly($rule->fee ?? 0));
            $subTotal += $fee;

            if ($rule->is_fallback) {
                $label = 'Out-of-zone distance charge';
            } else {
                $label = data_get($rule, 'zone.name') ?: data_get($rule, 'serviceArea.name') ?: $rule->label ?: 'Geographic';
            }

            if (!$rule->is_fallback && !str_ends_with(strtolower($label), 'distance charge')) {
                $label .= ' distance charge';
            }

            $lines->push([
                'details'          => sprintf('%s (%s %s x %s)', $label, round($distance, 2), $unit, Utils::moneyFormat($rule->fee, $this->currency)),
                'amount'           => $fee,
                'formatted_amount' => Utils::moneyFormat($fee, $this->currency),
                'currency'         => $this->currency,
                'code'             => 'MULTI_ZONE_DISTANCE_FEE',
            ]);
        }

        return [$subTotal, $lines];
    }

    protected function calculateMultiZoneDistances($waypoints, $rules, ?ServiceRateFee $fallbackRule = null, ?int $totalDistance = 0): array
    {
        $places = collect($waypoints)->map(fn ($place) => Place::createFromMixed($place))->filter()->values();

        if ($places->count() < 2 || (int) $totalDistance <= 0) {
            return $fallbackRule ? [['rule' => $fallbackRule, 'distance_m' => (float) ($totalDistance ?? 0)]] : [];
        }

        $reader         = new GeoJSONReader();
        $ruleGeometries = $rules->map(function ($rule) use ($reader) {
            return [
                'rule'      => $rule,
                'geometry'  => $this->readRateRuleGeometry($rule, $reader),
            ];
        })->filter(fn ($entry) => $entry['geometry'])->values();

        if ($ruleGeometries->isEmpty()) {
            return $fallbackRule ? [['rule' => $fallbackRule, 'distance_m' => (float) $totalDistance]] : [];
        }

        $distances    = [];
        $sampledTotal = 0;

        for ($i = 0; $i < $places->count() - 1; $i++) {
            $start = $this->getLngLatFromPlace($places[$i]);
            $end   = $this->getLngLatFromPlace($places[$i + 1]);

            if (!$start || !$end) {
                continue;
            }

            $legDistance = $this->haversineDistanceInMeters($start['lat'], $start['lng'], $end['lat'], $end['lng']);
            $steps       = max(1, min(500, (int) ceil($legDistance / 1000)));

            for ($step = 0; $step < $steps; $step++) {
                $fromRatio = $step / $steps;
                $toRatio   = ($step + 1) / $steps;
                $midRatio  = ($fromRatio + $toRatio) / 2;

                $from = $this->interpolateLngLat($start, $end, $fromRatio);
                $to   = $this->interpolateLngLat($start, $end, $toRatio);
                $mid  = $this->interpolateLngLat($start, $end, $midRatio);

                $sampleDistance = $this->haversineDistanceInMeters($from['lat'], $from['lng'], $to['lat'], $to['lng']);
                $sampledTotal += $sampleDistance;

                $matchedRule = $this->matchMultiZoneRule($mid, $ruleGeometries) ?: $fallbackRule;
                if (!$matchedRule instanceof ServiceRateFee) {
                    continue;
                }

                $key = $matchedRule->uuid ?? spl_object_hash($matchedRule);
                if (!isset($distances[$key])) {
                    $distances[$key] = ['rule' => $matchedRule, 'distance_m' => 0];
                }

                $distances[$key]['distance_m'] += $sampleDistance;
            }
        }

        if ($sampledTotal > 0 && $totalDistance > 0) {
            $scale = $totalDistance / $sampledTotal;
            foreach ($distances as &$entry) {
                $entry['distance_m'] *= $scale;
            }
            unset($entry);
        }

        return array_values($distances);
    }

    protected function readRateRuleGeometry(ServiceRateFee $rule, GeoJSONReader $reader)
    {
        $border = data_get($rule, 'zone.border') ?: data_get($rule, 'serviceArea.border');

        if (!$border) {
            return null;
        }

        try {
            if (is_object($border) && method_exists($border, 'toJson')) {
                return $reader->read($border->toJson());
            }

            if (is_array($border) || is_object($border)) {
                return $reader->read(json_encode($border, JSON_UNESCAPED_UNICODE));
            }

            if (is_string($border)) {
                return $reader->read($border);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    protected function matchMultiZoneRule(array $point, $ruleGeometries): ?ServiceRateFee
    {
        $brickPoint = \Brick\Geo\Point::fromText(sprintf('POINT (%F %F)', $point['lng'], $point['lat']), 4326);

        foreach ($ruleGeometries as $entry) {
            if ($entry['geometry']->contains($brickPoint)) {
                return $entry['rule'];
            }
        }

        return null;
    }

    protected function getLngLatFromPlace(?Place $place): ?array
    {
        if (!$place instanceof Place) {
            return null;
        }

        $point = $place->getLocationAsPoint();

        if (!$point || !method_exists($point, 'getLat') || !method_exists($point, 'getLng')) {
            return null;
        }

        return ['lat' => (float) $point->getLat(), 'lng' => (float) $point->getLng()];
    }

    protected function interpolateLngLat(array $start, array $end, float $ratio): array
    {
        return [
            'lat' => $start['lat'] + (($end['lat'] - $start['lat']) * $ratio),
            'lng' => $start['lng'] + (($end['lng'] - $start['lng']) * $ratio),
        ];
    }

    protected function haversineDistanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $latDelta    = deg2rad($lat2 - $lat1);
        $lngDelta    = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Find the ServiceRateFee based on the total distance.
     *
     * @param int $totalDistance the total distance in meters
     *
     * @return ServiceRateFee|null the ServiceRateFee instance if found, otherwise null
     */
    public function findServiceRateFeeByDistance(int $totalDistance): ?ServiceRateFee
    {
        $this->loadMissing('rateFees');

        // Convert meters to kilometers WITHOUT rounding up
        $distanceInKm = $totalDistance / 1000;

        // Ensure predictable order
        $rateFees = $this->rateFees->sortBy('distance');

        // Find the first tier that covers the distance
        foreach ($rateFees as $rateFee) {
            if ($distanceInKm <= $rateFee->distance) {
                return $rateFee;
            }
        }

        // If distance exceeds all tiers, use the largest tier
        return $rateFees->last();
    }

    /**
     * Find the ServiceRateFee based on the given number within the min and max range.
     *
     * @param int $number the number to check within the ServiceRateFee's min and max range
     *
     * @return ServiceRateFee|null the ServiceRateFee instance if found, otherwise null
     */
    public function findServiceRateFeeByMinMax(int $number): ?ServiceRateFee
    {
        $this->load('rateFees');

        $serviceRateFee = null;

        foreach ($this->rateFees as $rateFee) {
            if ($rateFee->isWithinMinMax($number)) {
                $serviceRateFee = $rateFee;
                break;
            }
        }

        // if no distance fee use the last
        if ($serviceRateFee === null) {
            $serviceRateFee = $this->rateFees->sortByDesc('max')->first();
        }

        return $serviceRateFee;
    }
}
