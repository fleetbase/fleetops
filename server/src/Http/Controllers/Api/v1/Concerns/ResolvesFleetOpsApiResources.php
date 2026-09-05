<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns;

use Fleetbase\FleetOps\Exceptions\PublicRelationNotFoundException;
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
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesFleetOpsApiResources
{
    protected function resolveUuid(string $modelClass, ?string $id, ?string $companyUuid = null): ?string
    {
        if (empty($id)) {
            return null;
        }

        return $this->resolveModel($modelClass, $id, $companyUuid)->uuid;
    }

    /**
     * @param string|null $companyUuid the company to scope the lookup to; defaults
     *                                 to the session company
     */
    protected function resolveModel(string $modelClass, string $id, ?string $companyUuid = null): Model
    {
        $instance = new $modelClass();
        $query    = $modelClass::query()->where(function ($query) use ($id, $instance) {
            $query->whereRaw('0 = 1');

            if (in_array('public_id', $instance->getFillable()) || method_exists($instance, 'getPublicIdType')) {
                $query->orWhere('public_id', $id);
            }

            if (in_array('internal_id', $instance->getFillable())) {
                $query->orWhere('internal_id', $id);
            }
        });

        $companyUuid = $companyUuid ?? session('company');

        if ($companyUuid && $this->modelHasColumn($instance, 'company_uuid')) {
            $query->where($instance->qualifyColumn('company_uuid'), $companyUuid);
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

    protected function rejectUuidIdentifiers(Request $request): void
    {
        $invalidKeys = array_unique(array_merge(
            $this->collectUuidIdentifierKeys($request->query->all()),
            $this->collectUuidIdentifierKeys($request->request->all())
        ));

        if (empty($invalidKeys)) {
            return;
        }

        $messages = [];
        foreach ($invalidKeys as $key) {
            $messages[$key] = ['UUID identifiers are not accepted by the public API. Use public_id or internal_id values instead.'];
        }

        throw ValidationException::withMessages($messages);
    }

    protected function collectUuidIdentifierKeys(array $input, string $prefix = ''): array
    {
        $keys = [];

        foreach ($input as $key => $value) {
            $path = $prefix ? $prefix . '.' . $key : (string) $key;

            if ($this->isUuidIdentifierKey((string) $key)) {
                $keys[] = $path;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->collectUuidIdentifierKeys($value, $path));
            }
        }

        return $keys;
    }

    protected function isUuidIdentifierKey(string $key): bool
    {
        return preg_match('/(^uuid$|_uuid$|Uuid$|UUID$)/', $key) === 1;
    }

    protected function applyPublicIdRelation(array &$input, string $requestKey, string $column, string $modelClass, $request, ?string $companyUuid = null): void
    {
        if (!$request->exists($requestKey)) {
            return;
        }

        $input[$column] = filled($request->input($requestKey))
            ? $this->resolveUuid($modelClass, $request->input($requestKey), $companyUuid)
            : null;
    }

    /**
     * Apply a set of public-ID relationship inputs in one pass.
     *
     * `$map` is keyed by the public request key and holds `[column, modelClass]`,
     * e.g. `['parent_fleet' => ['parent_fleet_uuid', Fleet::class]]`. A key that is
     * absent from the request is left untouched; a key sent empty clears the column.
     *
     * Resolution failures are rethrown as a PublicRelationNotFoundException so the
     * caller can say which input was at fault rather than answering with a bare
     * "not found" that names no field.
     *
     * @param array<string, array{0: string, 1: class-string}> $map
     *
     * @throws PublicRelationNotFoundException
     */
    protected function applyPublicIdRelations(array &$input, array $map, $request, ?string $companyUuid = null): void
    {
        foreach ($map as $requestKey => [$column, $modelClass]) {
            try {
                $this->applyPublicIdRelation($input, $requestKey, $column, $modelClass, $request, $companyUuid);
            } catch (ModelNotFoundException $exception) {
                $identifier = $request->input($requestKey);

                throw new PublicRelationNotFoundException($requestKey, is_scalar($identifier) ? (string) $identifier : null, $exception);
            }
        }
    }

    protected function allowedMorphTypes(): array
    {
        return [
            'fleet-ops:vehicle'                  => Vehicle::class,
            'vehicle'                            => Vehicle::class,
            Vehicle::class                       => Vehicle::class,
            'fleet-ops:driver'                   => Driver::class,
            'driver'                             => Driver::class,
            Driver::class                        => Driver::class,
            'fleet-ops:equipment'                => Equipment::class,
            'equipment'                          => Equipment::class,
            Equipment::class                     => Equipment::class,
            'fleet-ops:part'                     => Part::class,
            'part'                               => Part::class,
            Part::class                          => Part::class,
            'fleet-ops:vendor'                   => Vendor::class,
            'vendor'                             => Vendor::class,
            Vendor::class                        => Vendor::class,
            'fleet-ops:contact'                  => Contact::class,
            'contact'                            => Contact::class,
            Contact::class                       => Contact::class,
            'fleet-ops:device'                   => Device::class,
            'device'                             => Device::class,
            Device::class                        => Device::class,
            'fleet-ops:telematic'                => Telematic::class,
            'telematic'                          => Telematic::class,
            Telematic::class                     => Telematic::class,
            'fleet-ops:warranty'                 => Warranty::class,
            'warranty'                           => Warranty::class,
            Warranty::class                      => Warranty::class,
            'fleet-ops:fuel-report'              => FuelReport::class,
            'fuel-report'                        => FuelReport::class,
            FuelReport::class                    => FuelReport::class,
            'fleet-ops:fuel-provider-connection' => FuelProviderConnection::class,
            'fuel-provider-connection'           => FuelProviderConnection::class,
            FuelProviderConnection::class        => FuelProviderConnection::class,
            'fleet-ops:order'                    => Order::class,
            'order'                              => Order::class,
            Order::class                         => Order::class,
            'file'                               => File::class,
            File::class                          => File::class,
        ];
    }

    protected function modelHasColumn(Model $model, string $column): bool
    {
        return in_array($column, $model->getFillable()) || $column === $model->getKeyName();
    }
}
