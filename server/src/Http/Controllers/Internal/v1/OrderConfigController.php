<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderConfigRequest;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderConfigController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'order-config';

    /**
     * Creates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        // Create validation request
        $createOrderRequest  = CreateOrderConfigRequest::createFrom($request);
        $rules               = $createOrderRequest->rules();

        // Manually validate request
        $validator = Validator::make($request->input('orderConfig'), $rules);
        if ($validator->fails()) {
            return $createOrderRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->model->createRecordFromRequest($request);

            return ['order_config' => new $this->resource($record)];
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * Delete's an order config.
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteRecord($id, Request $request)
    {
        $orderConfig = $this->findOrderConfig($id);
        if (!$orderConfig) {
            return $this->errorResponse('No order config found.');
        }

        // `core_service` order configs cannot be deleted
        if ($orderConfig->core_service === 1) {
            return $this->errorResponse('Core service order config\'s cannot be deleted.');
        }

        if ($orderConfig) {
            $orderConfig->delete();

            $this->wrapResource();

            return $this->deletedResource($orderConfig);
        }

        return $this->errorResponse('Unable to delete order config.');
    }

    protected function findOrderConfig(string $id): ?OrderConfig
    {
        return OrderConfig::where('uuid', $id)->first();
    }

    protected function wrapResource(): void
    {
        $this->resource::wrap($this->resourceSingularlName);
    }

    protected function deletedResource(OrderConfig $orderConfig)
    {
        return new $this->resource($orderConfig);
    }

    protected function errorResponse(string $message)
    {
        return response()->error($message);
    }
}
