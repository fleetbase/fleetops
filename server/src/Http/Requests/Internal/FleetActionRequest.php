<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Support\Auth;

class FleetActionRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $action = $this->actionMethod();

        if ($action === 'assignVehicle') {
            return $this->can('fleet-ops assign-vehicle-for fleet');
        }

        if ($action === 'assignDriver') {
            return $this->can('fleet-ops assign-driver-for fleet');
        }

        if ($action === 'removeVehicle') {
            return $this->can('fleet-ops remove-vehicle-for fleet');
        }

        if ($action === 'removeDriver') {
            return $this->can('fleet-ops remove-driver-for fleet');
        }

        return $this->can('fleet-ops update fleet');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'fleet'   => 'string|exists:fleets,uuid',
            'driver'  => 'nullable|string|exists:drivers,uuid',
            'vehicle' => 'nullable|string|exists:vehicles,uuid',
        ];
    }

    protected function actionMethod(): string
    {
        return $this->route()->getActionMethod();
    }

    protected function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}
