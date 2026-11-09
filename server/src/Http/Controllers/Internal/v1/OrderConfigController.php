<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderConfigRequest;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigActions;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigPolicies;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTriggers;
use Fleetbase\Models\Company;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
        $createOrderRequest  = $this->createOrderConfigRequest($request);
        $rules               = $createOrderRequest->rules();

        // Manually validate request
        $validator = $this->makeOrderConfigValidator($request, $rules);
        if ($validator->fails()) {
            return $createOrderRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->createOrderConfigRecord($request);

            return $this->createdOrderConfigResource($record);
        } catch (FleetbaseRequestValidationException $e) {
            return $this->errorResponse($e->getErrors());
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse($e->getMessage());
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
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

        $orderConfig->delete();

        $this->wrapResource();

        return $this->deletedResource($orderConfig);
    }

    /**
     * The templates, policies, actions and trigger events order configs can use, for the console.
     */
    public function capabilities()
    {
        return response()->json([
            'templates' => OrderConfigTemplates::describe(),
            'policies'  => OrderConfigPolicies::describe(),
            'actions'   => OrderConfigActions::describe(),
            'events'    => OrderConfigTriggers::EVENTS,
        ]);
    }

    /**
     * Create a config from a template, under a name of the company's choosing.
     */
    public function createFromTemplate(Request $request)
    {
        $template = (string) $request->input('template');
        $name     = trim((string) $request->input('name'));
        if (!OrderConfigTemplates::has($template)) {
            return $this->errorResponse('Choose an order config template.');
        }
        if ($name === '') {
            return $this->errorResponse('Give the order config a name.');
        }

        $company = $this->currentCompany();
        if (!$company) {
            return $this->errorResponse('No organization in session.');
        }
        if (OrderConfig::where('company_uuid', $company->uuid)->where(fn ($query) => $query->where('name', $name)->orWhere('key', Str::slug($name)))->exists()) {
            return $this->errorResponse('An order config with this name already exists.');
        }

        return $this->createdOrderConfigResource(OrderConfigTemplates::create($template, $company, $name));
    }

    protected function currentCompany(): ?Company
    {
        return Auth::getCompany();
    }

    protected function findOrderConfig(string $id): ?OrderConfig
    {
        return OrderConfig::where('uuid', $id)->first();
    }

    protected function createOrderConfigRequest(Request $request)
    {
        return CreateOrderConfigRequest::createFrom($request);
    }

    protected function makeOrderConfigValidator(Request $request, array $rules)
    {
        return Validator::make($request->input('orderConfig'), $rules);
    }

    protected function createOrderConfigRecord(Request $request)
    {
        return $this->model->createRecordFromRequest($request);
    }

    protected function createdOrderConfigResource($record): array
    {
        return ['order_config' => new $this->resource($record)];
    }

    protected function wrapResource(): void
    {
        $this->resource::wrap($this->resourceSingularlName);
    }

    protected function deletedResource(OrderConfig $orderConfig)
    {
        return new $this->resource($orderConfig);
    }

    protected function errorResponse(string|array $message)
    {
        return response()->error($message);
    }
}
