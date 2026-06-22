<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait ResolvesFleetOpsApiResources
{
    protected function resolveUuid(string $modelClass, ?string $id): ?string
    {
        if (empty($id)) {
            return null;
        }

        return $this->resolveModel($modelClass, $id)->uuid;
    }

    protected function resolveModel(string $modelClass, string $id): Model
    {
        $instance = new $modelClass();
        $query    = $modelClass::query()->where(function ($query) use ($id, $instance) {
            $query->where('uuid', $id);

            if (in_array('public_id', $instance->getFillable()) || method_exists($instance, 'getPublicIdType')) {
                $query->orWhere('public_id', $id);
            }

            if (in_array('internal_id', $instance->getFillable())) {
                $query->orWhere('internal_id', $id);
            }
        });

        if (session('company') && $this->modelHasColumn($instance, 'company_uuid')) {
            $query->where($instance->qualifyColumn('company_uuid'), session('company'));
        }

        $model = $query->first();
        if ($model) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel($modelClass, $id);
    }

    protected function resolveMorph(?string $type, ?string $id): array
    {
        if (empty($type) || empty($id)) {
            return [null, null];
        }

        $modelClass = Utils::getMutationType($type);
        $model      = $this->resolveModel($modelClass, $id);

        return [$modelClass, $model->uuid];
    }

    protected function applyPublicIdRelation(array &$input, string $requestKey, string $column, string $modelClass, $request): void
    {
        if (!$request->exists($requestKey)) {
            return;
        }

        $input[$column] = filled($request->input($requestKey))
            ? $this->resolveUuid($modelClass, $request->input($requestKey))
            : null;
    }

    protected function allowedMorphTypes(): array
    {
        return [
            'fleet-ops:vehicle'              => Vehicle::class,
            'vehicle'                        => Vehicle::class,
            Vehicle::class                   => Vehicle::class,
            'fleet-ops:driver'               => Driver::class,
            'driver'                         => Driver::class,
            Driver::class                    => Driver::class,
            'fleet-ops:equipment'            => Equipment::class,
            'equipment'                      => Equipment::class,
            Equipment::class                 => Equipment::class,
            'fleet-ops:part'                 => Part::class,
            'part'                           => Part::class,
            Part::class                      => Part::class,
            'fleet-ops:vendor'               => Vendor::class,
            'vendor'                         => Vendor::class,
            Vendor::class                    => Vendor::class,
            'fleet-ops:contact'              => Contact::class,
            'contact'                        => Contact::class,
            Contact::class                   => Contact::class,
            'fleet-ops:device'               => Device::class,
            'device'                         => Device::class,
            Device::class                    => Device::class,
            'fleet-ops:telematic'            => Telematic::class,
            'telematic'                      => Telematic::class,
            Telematic::class                 => Telematic::class,
            'fleet-ops:warranty'             => Warranty::class,
            'warranty'                       => Warranty::class,
            Warranty::class                  => Warranty::class,
            'fleet-ops:fuel-report'          => FuelReport::class,
            'fuel-report'                    => FuelReport::class,
            FuelReport::class                => FuelReport::class,
            'fleet-ops:fuel-provider-connection' => FuelProviderConnection::class,
            'fuel-provider-connection'       => FuelProviderConnection::class,
            FuelProviderConnection::class    => FuelProviderConnection::class,
            'fleet-ops:order'                => Order::class,
            'order'                          => Order::class,
            Order::class                     => Order::class,
            'file'                           => File::class,
            File::class                      => File::class,
        ];
    }

    protected function modelHasColumn(Model $model, string $column): bool
    {
        return in_array($column, $model->getFillable()) || $column === $model->getKeyName();
    }
}
