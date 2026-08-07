<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceQuoteController extends Controller
{
    /**
     * Query for Fleetbase ServiceQuote resources.
     *
     * @return \Fleetbase\FleetOps\Http\Resources\ServiceQuoteCollection
     */
    public function query(QueryServiceQuotesRequest $request)
    {
        $payload          = $request->input('payload');
        $currency         = $request->input('currency');
        $facilitator      = $request->input('facilitator');
        $scheduledAt      = $request->input('scheduled_at');
        $service          = $request->input('service', 'all'); // the specific service rate to query - defaults to `all`
        $serviceType      = $request->input('service_type'); // the specific type of service rate to query
        $single           = $request->boolean('single');
        $isRouteOptimized = $request->boolean('is_route_optimized', true);
        $requestId        = $this->generateServiceQuoteRequestId();

        if (Utils::isPublicId($payload)) {
            $payload = Payload::with(['pickup', 'dropoff', 'waypoints', 'entities'])
                ->where('public_id', $payload)
                ->first();
        }

        if (!$payload instanceof Payload) {
            return $this->queryFromPreliminary($request);
        }

        // if facilitator is an integrated partner resolve service quotes from bridge
        if ($facilitator && Str::startsWith($facilitator, 'integrated_vendor')) {
            $integratedVendor = IntegratedVendor::where('public_id', $facilitator)->first();
            $serviceQuotes    = [];

            if ($integratedVendor) {
                try {
                    $serviceQuotes = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPayload($payload, $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'errors' => [$e->getMessage()],
                    ], 400);
                }
            }

            // send single quote back
            if ($single) {
                return $this->serviceQuoteResource($serviceQuotes);
            }

            if (!is_array($serviceQuotes)) {
                $serviceQuotes = [$serviceQuotes];
            }

            return $this->serviceQuoteResourceCollection($serviceQuotes);
        }

        // get all waypoints
        $waypoints = $payload->getAllStops();

        // if quote for single service
        if ($service && $service !== 'all') {
            $serviceRate   = $this->findServiceRateForQuote($service, $currency);
            $serviceQuotes = collect();

            if ($serviceRate) {
                [$subTotal, $lines] = $serviceRate->quote($payload);

                $quote = $this->createServiceQuote([
                    'request_id'        => $requestId,
                    'company_uuid'      => $serviceRate->company_uuid,
                    'service_rate_uuid' => $serviceRate->uuid,
                    'amount'            => $subTotal,
                    'currency'          => $serviceRate->currency,
                ]);
                $quote->setRelation('serviceRate', $serviceRate);

                $items = $lines->map(function ($line) use ($quote) {
                    return $this->createServiceQuoteItem($this->serviceQuoteItemInput($quote, $line));
                });

                $quote->setRelation('items', $items);
                $serviceQuotes->push($quote);

                // if single quotation requested
                if ($single) {
                    return $this->serviceQuoteResource($quote);
                }

                return $this->serviceQuoteResourceCollection($serviceQuotes);
            }
        }

        // get all service rates
        $serviceRates = $this->getServicableServiceRates(
            $waypoints,
            $serviceType,
            $currency,
            function ($query) use ($request) {
                $query->where('company_uuid', $request->session()->get('company'));
            }
        );
        $serviceQuotes = collect();

        // calculate quotes
        foreach ($serviceRates as $serviceRate) {
            [$subTotal, $lines] = $serviceRate->quote($payload);

            $quote = $this->createServiceQuote([
                'request_id'        => $requestId,
                'company_uuid'      => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'amount'            => $subTotal,
                'currency'          => $serviceRate->currency,
            ]);
            $quote->setRelation('serviceRate', $serviceRate);

            $items = $lines->map(function ($line) use ($quote) {
                return $this->createServiceQuoteItem([
                    'service_quote_uuid' => $quote->uuid,
                    'amount'             => $line['amount'],
                    'currency'           => $line['currency'],
                    'details'            => $line['details'],
                    'code'               => $line['code'],
                ]);
            });

            $quote->setRelation('items', $items);
            $serviceQuotes->push($quote);
        }

        // if single quotation requested
        if ($single) {
            // find the best quotation
            $bestQuote = $this->bestQuote($serviceQuotes);

            return $this->serviceQuoteResource($bestQuote);
        }

        return $this->serviceQuoteResourceCollection($serviceQuotes);
    }

    /**
     * Query for Fleetbase ServiceQuote from preliminary data resources.
     *
     * @param \Fleetbase\Http\Requests\QueryServiceQuotesRequest $request
     *
     * @return \Fleetbase\Http\Resources\ServiceQuoteCollection
     */
    public function queryFromPreliminary(QueryServiceQuotesRequest $request)
    {
        $facilitator      = $request->input('facilitator');
        $scheduledAt      = $request->input('scheduled_at');
        $service          = $request->input('service', 'all'); // the specific service rate to query - defaults to `all`
        $serviceType      = $request->input('service_type'); // the specific type of service rate to query
        $isCashOnDelivery = $request->has('cod');
        $currency         = $request->has('currency');
        $totalDistance    = $request->input('distance');
        $totalTime        = $request->input('time');
        $preliminaryData  = $this->preliminaryDataFromRequest($request);
        $pickup           = $preliminaryData['pickup'];
        $dropoff          = $preliminaryData['dropoff'];
        $return           = $preliminaryData['return'];
        $waypoints        = $preliminaryData['waypoints'];
        $entities         = $preliminaryData['entities'];
        $single           = $request->boolean('single');
        $isRouteOptimized = $request->boolean('is_route_optimized', true);

        $requestId     = $this->generateServiceQuoteRequestId();
        $serviceQuotes = [];

        if (Utils::isNotScalar($pickup)) {
            $pickup = $this->createPlaceFromMixed($pickup);
        }

        if (Utils::isNotScalar($dropoff)) {
            $dropoff = $this->createPlaceFromMixed($dropoff);
        }

        if (Utils::isPublicId($pickup)) {
            $pickup = $this->findPlaceByPublicId($pickup);
        }

        if (Utils::isPublicId($dropoff)) {
            $dropoff = $this->findPlaceByPublicId($dropoff);
        }

        // convert waypoints to place instances
        $waypoints = collect($waypoints)->mapInto(Place::class);
        $entities  = collect($entities)->mapInto(Entity::class);

        // should all be Place like
        $waypoints     = $this->preliminaryStops($pickup, $waypoints, $dropoff);
        $endpointCount = $this->endpointCount($pickup, $dropoff);

        // if facilitator is an integrated partner resolve service quotes from bridge
        if ($facilitator && Utils::isIntegratedVendorId($facilitator)) {
            $integratedVendor = IntegratedVendor::where('company_uuid', session('company'))->where(function ($q) use ($facilitator) {
                $q->where('public_id', $facilitator);
                $q->orWhere('provider', $facilitator);
            })->first();

            if ($integratedVendor) {
                try {
                    /** @var \Fleetbase\Models\ServiceQuote $serviceQuote */
                    $serviceQuote = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPreliminaryPayload($waypoints, $entities, $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'errors' => [$e->getMessage()],
                    ], 400);
                }
            }

            // set preliminary data to meta
            $serviceQuote->updateMeta('preliminary_data', $preliminaryData);

            // send single quote back
            if ($single) {
                return $this->serviceQuoteResource($serviceQuote);
            }

            if (!is_array($serviceQuote)) {
                $serviceQuote = [$serviceQuote];
            }

            return $this->serviceQuoteResourceCollection($serviceQuote);
        }

        // if no total distance recalculate totalDistance and totalTime based on waypoints collected
        if (!$totalDistance) {
            $matrix = $this->distanceMatrix([$waypoints->first()], $waypoints->skip(1));

            // set totalDistance and totalTime
            $totalDistance = $matrix->distance ?? 0;
            $totalTime     = $matrix->time ?? 0;
        }

        // if quote for single service
        if ($service !== 'all') {
            $serviceRate   = $this->findServiceRateByUuid($service);
            $serviceQuotes = collect();

            if ($serviceRate) {
                [$subTotal, $lines] = $serviceRate->quoteFromPreliminaryData($entities, $waypoints, $totalDistance, $totalTime, $isCashOnDelivery, $endpointCount);

                $quote = $this->createServiceQuote([
                    'request_id'        => $requestId,
                    'company_uuid'      => $serviceRate->company_uuid,
                    'service_rate_uuid' => $serviceRate->uuid,
                    'amount'            => $subTotal,
                    'currency'          => $serviceRate->currency,
                ]);
                $quote->setRelation('serviceRate', $serviceRate);

                // set preliminary data to meta
                $quote->updateMeta('preliminary_data', $preliminaryData);

                $items = $lines->map(function ($line) use ($quote) {
                    return $this->createServiceQuoteItem($this->serviceQuoteItemInput($quote, $line));
                });

                $quote->setRelation('items', $items);
                $serviceQuotes->push($quote);

                if ($single) {
                    return $this->serviceQuoteResource($quote);
                }

                return $this->serviceQuoteResourceCollection($serviceQuotes);
            }
        }

        // get all service rates
        $serviceRates = $this->getServicableServiceRates(
            $waypoints,
            $serviceType,
            $currency,
            function ($query) use ($request) {
                $query->where('company_uuid', $request->session()->get('company'));
            }
        );
        $serviceQuotes = collect();

        // calculate quotes
        foreach ($serviceRates as $serviceRate) {
            [$subTotal, $lines] = $serviceRate->quoteFromPreliminaryData($entities, $waypoints, $totalDistance, $totalTime, $isCashOnDelivery, $endpointCount);

            $quote = $this->createServiceQuote([
                'request_id'        => $requestId,
                'company_uuid'      => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'amount'            => $subTotal,
                'currency'          => $serviceRate->currency,
            ]);
            $quote->setRelation('serviceRate', $serviceRate);

            // set preliminary data to meta
            $quote->updateMeta('preliminary_data', $preliminaryData);

            $items = $lines->map(function ($line) use ($quote) {
                return $this->createServiceQuoteItem($this->serviceQuoteItemInput($quote, $line));
            });

            $quote->setRelation('items', $items);
            $serviceQuotes->push($quote);
        }

        // if single quotation requested
        if ($single) {
            // find the best quotation
            $bestQuote = $this->bestQuote($serviceQuotes);

            return $this->serviceQuoteResource($bestQuote);
        }

        return $this->serviceQuoteResourceCollection($serviceQuotes);
    }

    protected function generateServiceQuoteRequestId(): string
    {
        return ServiceQuote::generatePublicId('request');
    }

    protected function createPlaceFromMixed(mixed $value): Place
    {
        return Place::createFromMixed($value);
    }

    protected function findPlaceByPublicId(string $publicId): ?Place
    {
        return Place::where('public_id', $publicId)->first();
    }

    protected function distanceMatrix(array $origins, iterable $destinations): mixed
    {
        return Utils::distanceMatrix($origins, $destinations);
    }

    protected function findServiceRateForQuote(string $service, ?string $currency): ?ServiceRate
    {
        return ServiceRate::where(
            function ($query) use ($service) {
                $query->where('uuid', $service)->orWhere('public_id', $service);
            })->where(
                function ($q) use ($currency) {
                    if ($currency) {
                        $q->where(DB::raw('lower(currency)'), strtolower($currency));
                    }
                })->first();
    }

    protected function findServiceRateByUuid(string $service): ?ServiceRate
    {
        return ServiceRate::where('uuid', $service)->first();
    }

    protected function getServicableServiceRates(iterable $waypoints, ?string $serviceType, mixed $currency, callable $callback): iterable
    {
        return ServiceRate::getServicableForPlaces($waypoints, $serviceType, $currency, $callback);
    }

    protected function createServiceQuote(array $attributes): ServiceQuote
    {
        return ServiceQuote::create($attributes);
    }

    protected function createServiceQuoteItem(array $attributes): ServiceQuoteItem
    {
        return ServiceQuoteItem::create($attributes);
    }

    protected function serviceQuoteResource(ServiceQuote $serviceQuote)
    {
        return new ServiceQuoteResource($serviceQuote);
    }

    protected function serviceQuoteResourceCollection(iterable $serviceQuotes)
    {
        return ServiceQuoteResource::collection($serviceQuotes);
    }

    protected function preliminaryDataFromRequest(Request $request): array
    {
        return [
            'pickup'    => $this->requestFirst($request, ['payload.pickup', 'pickup']),
            'dropoff'   => $this->requestFirst($request, ['payload.dropoff', 'dropoff']),
            'return'    => $this->requestFirst($request, ['payload.return', 'return']),
            'waypoints' => $this->requestFirst($request, ['payload.waypoints', 'waypoints'], []),
            'entities'  => $this->requestFirst($request, ['payload.entities', 'entities']),
            'cod'       => $request->has('cod'),
            'currency'  => $request->has('currency'),
        ];
    }

    protected function requestFirst(Request $request, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if ($request->has($key)) {
                return $request->input($key);
            }
        }

        return $default;
    }

    protected function preliminaryStops(mixed $pickup, iterable $waypoints, mixed $dropoff): \Illuminate\Support\Collection
    {
        return collect([$pickup, ...$waypoints, $dropoff])->filter();
    }

    protected function endpointCount(mixed $pickup, mixed $dropoff): int
    {
        return (int) ($pickup instanceof Place) + (int) ($dropoff instanceof Place);
    }

    protected function serviceQuoteItemInput(ServiceQuote $quote, array $line): array
    {
        return [
            'service_quote_uuid' => $quote->uuid,
            'amount'             => $line['amount'],
            'currency'           => $line['currency'],
            'details'            => $line['details'],
            'code'               => $line['code'],
        ];
    }

    protected function bestQuote(iterable $serviceQuotes): mixed
    {
        return collect($serviceQuotes)->sortBy('amount')->first();
    }

    /**
     * Finds a single Fleetbase ServiceQuote resources.
     *
     * @return \Fleetbase\Http\Resources\ServiceQuoteCollection
     */
    public function find($id)
    {
        // find for the serviceQuote
        try {
            $serviceQuote = $this->findServiceQuote($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse([
                'error' => 'ServiceQuote resource not found.',
            ], 404);
        }

        // response the serviceQuote resource
        return $this->serviceQuoteResource($serviceQuote);
    }

    protected function findServiceQuote(string $id): ServiceQuote
    {
        return ServiceQuote::findRecordOrFail($id);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
