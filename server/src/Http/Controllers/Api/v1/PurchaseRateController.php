<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreatePurchaseRateRequest;
use Fleetbase\FleetOps\Http\Resources\v1\PurchaseRate as PurchaseRateResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PurchaseRateController extends Controller
{
    /**
     * Creates a new Fleetbase PurchaseRate resource.
     *
     * @param \Fleetbase\Http\Requests\CreatePurchaseRateRequest $request
     *
     * @return \Fleetbase\Http\Resources\PurchaseRate
     */
    public function create(CreatePurchaseRateRequest $request)
    {
        $input       = $this->purchaseRateInputFromRequest($request);
        $createOrder = $request->boolean('create_order', false);
        $order       = null;

        // make sure company is set
        $input['company_uuid'] = session('company');

        // service_quote assignment
        if ($request->has('service_quote')) {
            $input['service_quote_uuid'] = Utils::getUuid('service_quotes', [
                'public_id'    => $request->input('service_quote'),
                'company_uuid' => session('company'),
            ]);
        }

        // order assignment
        if ($request->has('order')) {
            $orderUuid = Utils::getUuid('orders', [
                'public_id'    => $request->input('order'),
                'company_uuid' => session('company'),
            ]);

            $order = Order::where('uuid', $orderUuid)->first();

            if ($order instanceof Order) {
                $input = array_merge($input, $this->purchaseRateInputFromOrder($order, $input['company_uuid']));
            }
        } elseif ($createOrder) {
            // create order from service quote
            $serviceQuote = ServiceQuote::where('uuid', $input['service_quote_uuid'])->orWhere('public_id', $request->input('service_quote'))->first();
            $order        = $this->createOrderFromServiceQuote($serviceQuote, $request);
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = Utils::getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('customer'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($customer)) {
                $input = array_merge($input, $this->purchaseRateCustomerInputFromLookup($customer));
            }
        }

        // create the purchaseRate
        $purchaseRate = PurchaseRate::create($input);

        if ($order instanceof Order) {
            $order->attachPurchaseRate($purchaseRate);
        }

        // response the driver resource
        return new PurchaseRateResource($purchaseRate);
    }

    protected function purchaseRateInputFromRequest(Request $request): array
    {
        return $request->only(['meta']);
    }

    protected function purchaseRateInputFromOrder(Order $order, ?string $fallbackCompanyUuid = null): array
    {
        return [
            'payload_uuid'  => $order->payload_uuid,
            'customer_uuid' => $order->customer_uuid,
            'customer_type' => $order->customer_type,
            'company_uuid'  => $order->company_uuid ?? $fallbackCompanyUuid,
        ];
    }

    protected function purchaseRateCustomerInputFromLookup(array $customer): array
    {
        return [
            'customer_uuid' => Utils::get($customer, 'uuid'),
            'customer_type' => Utils::getModelClassName(Utils::get($customer, 'table')),
        ];
    }

    /**
     * Create an Order from Service Quote.
     *
     * @param \Fleetbase\Models\ServiceQuote                     $serviceQuote
     * @param \Fleetbase\Http\Requests\CreatePurchaseRateRequest $request
     *
     * @return \Fleetbase\Models\Order|null
     */
    private function createOrderFromServiceQuote(?ServiceQuote $serviceQuote, CreatePurchaseRateRequest $request): ?Order
    {
        // if integrated vendor service quote create order with vendor first then create fleetbase order
        $integratedVendorOrder = null;

        if ($serviceQuote->fromIntegratedVendor()) {
            // create order with integrated vendor, then resume fleetbase order creation
            try {
                $integratedVendorOrder = $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
            } catch (\Exception $e) {
                return response()->apiError($e->getMessage());
            }
        }

        // create order input
        $input = [];

        // create order using preliminary data of service quote
        if ($serviceQuote->hasMeta('preliminary_data')) {
            $preliminaryData = $serviceQuote->getMeta('preliminary_data');
            $pickup          = Utils::get($preliminaryData, 'pickup');
            $dropoff         = Utils::get($preliminaryData, 'dropoff');
            $return          = Utils::get($preliminaryData, 'return');
            $entities        = Utils::get($preliminaryData, 'entities');
            $waypoints       = Utils::get($preliminaryData, 'waypoints', []);

            // create payload from preliminary data
            $payload = new Payload();
            $payload->setPickup($pickup);
            $payload->setDropoff($dropoff);
            $payload->setDropoff($return);
            $payload->setWaypoints($waypoints);
            $payload->setEntities($entities);
            $payload->save();

            // now create order
            $input['payload_uuid'] = $payload->uuid;
        }

        // set payload using service quote
        if ($serviceQuote->payload) {
            $input['payload_uuid'] = $serviceQuote->payload->uuid;
        }

        // attempt to set order type from service rate
        if ($serviceQuote->serviceRate instanceof ServiceRate) {
            $input['type'] = $serviceQuote->serviceRate->type;
        } else {
            $input['type'] = 'default';
        }

        // create the order
        $order = Order::create($input);

        if ($order instanceof Order) {
            // notify driver if assigned
            $order->notifyDriverAssigned();

            // set driving distance and time
            $order->setPreliminaryDistanceAndTime();

            // if it's integrated vendor order apply to meta
            if ($integratedVendorOrder) {
                $order->updateMeta([
                    'integrated_vendor'       => $serviceQuote->integratedVendor->public_id,
                    'integrated_vendor_order' => $integratedVendorOrder,
                ]);
            }

            return $order;
        }

        return null;
    }

    /**
     * Query for Fleetbase PurchaseRate resources.
     *
     * @return \Fleetbase\Http\Resources\PurchaseRateCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryPurchaseRates($request);

        return $this->purchaseRateResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase PurchaseRate resources.
     *
     * @return \Fleetbase\Http\Resources\PurchaseRateCollection
     */
    public function find($id, Request $request)
    {
        // find for the purchaseRate
        try {
            $purchaseRate = $this->findPurchaseRate($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'PurchaseRate resource not found.',
                ],
                404
            );
        }

        // response the purchaseRate resource
        return $this->purchaseRateResource($purchaseRate);
    }

    protected function queryPurchaseRates(Request $request)
    {
        return PurchaseRate::queryWithRequest($request);
    }

    protected function findPurchaseRate(string $id): PurchaseRate
    {
        return PurchaseRate::findRecordOrFail($id);
    }

    protected function purchaseRateResource(PurchaseRate $purchaseRate)
    {
        return new PurchaseRateResource($purchaseRate);
    }

    protected function purchaseRateResourceCollection($results)
    {
        return PurchaseRateResource::collection($results);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
