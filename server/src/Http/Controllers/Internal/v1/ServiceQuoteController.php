<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceQuoteController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'service_quote';

    /**
     * Query service quotes based on payload from order.
     *
     * @return \Illuminate\Http\Response
     */
    public function queryRecord(Request $request)
    {
        $payload          = $request->input('payload');
        $currency         = $request->input('currency');
        $facilitator      = $request->input('facilitator');
        $scheduledAt      = $request->input('scheduled_at');
        $service          = $request->input('service', 'all'); // the specific service rate to query - defaults to `all`
        $serviceType      = $request->input('service_type'); // the specific type of service rate to query
        $single           = $request->boolean('single');
        $isRouteOptimized = $request->boolean('is_route_optimized', true);

        $requestId = ServiceQuote::generatePublicId('request');

        if (is_string($payload)) {
            $payload = Payload::with(['pickup', 'dropoff', 'waypoints', 'entities'])
                ->where('public_id', $payload)
                ->orWhere('uuid', $payload)
                ->first();
        }

        if (!$payload instanceof Payload) {
            return $this->preliminaryQuery($request);
        }

        // if facilitator is an integrated partner resolve service quotes from bridge
        if ($facilitator && Str::startsWith($facilitator, 'integrated_vendor')) {
            $integratedVendor = IntegratedVendor::where('public_id', $facilitator)->first();
            $serviceQuotes    = [];

            if ($integratedVendor) {
                try {
                    $serviceQuotes = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPayload($payload, $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return response()->json([
                        'errors' => [$e->getMessage()],
                    ], 400);
                }
            }

            // send single quote back
            if ($single) {
                return response()->json($serviceQuotes);
            }

            if (!is_array($serviceQuotes)) {
                $serviceQuotes = [$serviceQuotes];
            }

            return response()->json($serviceQuotes);
        }

        // get all waypoints
        $waypoints = $payload->getAllStops()->mapInto(Place::class);

        // if quote for single service
        if ($service && $service !== 'all') {
            $serviceRate = ServiceRate::where('uuid', $service)->where(function ($q) use ($currency) {
                if ($currency) {
                    $q->where(DB::raw('lower(currency)'), strtolower($currency));
                }
            })->first();
            $serviceQuotes = [];

            if ($serviceRate) {
                [$subTotal, $lines] = $serviceRate->quote($payload);

                $quote = ServiceQuote::create([
                    'request_id'        => $requestId,
                    'company_uuid'      => $serviceRate->company_uuid,
                    'service_rate_uuid' => $serviceRate->uuid,
                    'amount'            => $subTotal,
                    'currency'          => $serviceRate->currency,
                ]);

                $items = $lines->map(function ($line) use ($quote) {
                    return ServiceQuoteItem::create([
                        'service_quote_uuid' => $quote->uuid,
                        'amount'             => $line['amount'],
                        'currency'           => $line['currency'],
                        'details'            => $line['details'],
                        'code'               => $line['code'],
                    ]);
                });

                $quote->setRelation('items', $items);

                // if single quotation requested
                if ($single) {
                    return response()->json($quote);
                }

                $serviceQuotes[] = $quote;

                return response()->json($serviceQuotes);
            }
        }

        // get all service rates
        $serviceRates = ServiceRate::getServicableForPlaces(
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

            $quote = ServiceQuote::create([
                'request_id'        => $requestId,
                'company_uuid'      => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'amount'            => $subTotal,
                'currency'          => $serviceRate->currency,
            ]);

            $items = $lines->map(function ($line) use ($quote) {
                return ServiceQuoteItem::create([
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
            $bestQuote = $serviceQuotes->sortBy('amount')->first();

            return response()->json($bestQuote);
        }

        return response()->json($serviceQuotes);
    }

    /**
     * Query service quotes based on preliminary payload from order.
     *
     * @return \Illuminate\Http\Response
     */
    public function preliminaryQuery(Request $request)
    {
        $facilitator      = $request->input('facilitator');
        $scheduledAt      = $request->input('scheduled_at');
        $service          = $request->input('service', 'all'); // the specific service rate to query - defaults to `all`
        $serviceType      = $request->input('service_type'); // the specific type of service rate to query
        $isCashOnDelivery = $request->has('cod');
        $currency         = $request->has('currency');
        $totalDistance    = $request->input('distance');
        $totalTime        = $request->input('time');
        $pickup           = $request->or(['payload.pickup', 'payload.pickup_uuid', 'pickup']);
        $dropoff          = $request->or(['payload.dropoff', 'payload.dropoff_uuid', 'dropoff']);
        $waypoints        = $request->or(['payload.waypoints', 'waypoints'], []);
        $entities         = $request->or(['payload.entities', 'entities']);
        $single           = $request->boolean('single');
        $isRouteOptimized = $request->boolean('is_route_optimized', true);

        $requestId     = ServiceQuote::generatePublicId('request');
        $serviceQuotes = [];

        if (Utils::isNotScalar($pickup)) {
            $pickup = Place::createFromMixed($pickup);
        }

        if (Utils::isNotScalar($dropoff)) {
            $dropoff = Place::createFromMixed($dropoff);
        }

        if (Str::isUuid($pickup)) {
            $pickup = Place::where('uuid', $pickup)->first();
        }

        if (Str::isUuid($dropoff)) {
            $dropoff = Place::where('uuid', $dropoff)->first();
        }

        // convert waypoints to place instances
        $waypoints = collect($waypoints)->mapInto(Place::class);
        $entities  = collect($entities)->mapInto(Entity::class);

        // should all be Place like
        $waypoints = collect([$pickup, ...$waypoints, $dropoff])->filter();

        // if facilitator is an integrated partner resolve service quotes from bridge
        if ($facilitator && Str::startsWith($facilitator, 'integrated_vendor')) {
            $integratedVendor = IntegratedVendor::where('public_id', $facilitator)->orWhere('provider', $facilitator)->first();

            if ($integratedVendor) {
                try {
                    $serviceQuotes = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPreliminaryPayload($waypoints, $entities, $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return response()->json([
                        'errors' => [$e->getMessage()],
                    ], 400);
                }
            }

            // send single quote back
            if ($single) {
                return response()->json($serviceQuotes);
            }

            if (!is_array($serviceQuotes)) {
                $serviceQuotes = [$serviceQuotes];
            }

            return response()->json($serviceQuotes);
        }

        // if no total distance recalculate totalDistance and totalTime based on waypoints collected
        if (!$totalDistance) {
            $matrix = Utils::distanceMatrix([$waypoints->first()], $waypoints->skip(1)->values());

            // set totalDistance and totalTime
            $totalDistance = $matrix->distance ?? 0;
            $totalTime     = $matrix->time ?? 0;
        }

        // if quote for single service
        if ($service && $service !== 'all') {
            $serviceRate   = ServiceRate::where('uuid', $service)->first();
            $serviceQuotes = collect();

            if ($serviceRate) {
                [$subTotal, $lines] = $serviceRate->quoteFromPreliminaryData($entities, $waypoints, $totalDistance, $totalTime, $isCashOnDelivery);

                $quote = ServiceQuote::create([
                    'request_id'        => $requestId,
                    'company_uuid'      => $serviceRate->company_uuid,
                    'service_rate_uuid' => $serviceRate->uuid,
                    'amount'            => $subTotal,
                    'currency'          => $serviceRate->currency,
                ]);

                $items = $lines->map(function ($line) use ($quote) {
                    return ServiceQuoteItem::create([
                        'service_quote_uuid' => $quote->uuid,
                        'amount'             => $line['amount'],
                        'currency'           => $line['currency'],
                        'details'            => $line['details'],
                        'code'               => $line['code'],
                    ]);
                });

                $quote->setRelation('items', $items);
                $serviceQuotes->push($quote);

                // if requesting single
                if ($single) {
                    return response()->json($quote);
                }

                return response()->json($serviceQuotes);
            }
        }

        // get all service rates
        $serviceRates = ServiceRate::getServicableForPlaces(
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
            [$subTotal, $lines] = $serviceRate->quoteFromPreliminaryData($entities, $waypoints, $totalDistance, $totalTime, $isCashOnDelivery);

            $quote = ServiceQuote::create([
                'request_id'        => $requestId,
                'company_uuid'      => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'amount'            => $subTotal,
                'currency'          => $serviceRate->currency,
            ]);

            $items = $lines->map(function ($line) use ($quote) {
                return ServiceQuoteItem::create([
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
            $bestQuote = $serviceQuotes->sortBy('amount')->first();

            return response()->json($bestQuote);
        }

        return response()->json($serviceQuotes);
    }
}
