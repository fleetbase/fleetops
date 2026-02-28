<?php

namespace Fleetbase\FleetOps\Rules;

use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Str;

class ResolvableVehicle implements Rule
{
    /**
     * The resolved vehicle instance, if found.
     *
     * @var Vehicle|null
     */
    protected ?Vehicle $resolved = null;

    /**
     * Determine if the validation rule passes.
     *
     * Accepts:
     *  - A public_id string (e.g. "vehicle_abc123")
     *  - A UUID string (e.g. "550e8400-e29b-41d4-a716-446655440000")
     *  - An array/object containing an "id", "public_id", or "uuid" key
     *
     * @param string $attribute
     * @param mixed  $value
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $identifier = $this->extractIdentifier($value);

        if (empty($identifier)) {
            return true; // nullable — let the nullable rule handle empty values
        }

        if (Str::isUuid($identifier)) {
            $this->resolved = Vehicle::where('uuid', $identifier)->first();
        } else {
            $this->resolved = Vehicle::where('public_id', $identifier)->first();
        }

        return $this->resolved !== null;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute must be a valid vehicle public ID, UUID, or vehicle object.';
    }

    /**
     * Extract a string identifier from the given value.
     *
     * Handles a plain string, an associative array, or a stdClass object.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    protected function extractIdentifier($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return data_get($value, 'id')
                ?? data_get($value, 'public_id')
                ?? data_get($value, 'uuid')
                ?? null;
        }

        if (is_object($value)) {
            return data_get($value, 'id')
                ?? data_get($value, 'public_id')
                ?? data_get($value, 'uuid')
                ?? null;
        }

        return null;
    }

    /**
     * Get the resolved Vehicle model instance after validation passes.
     *
     * @return Vehicle|null
     */
    public function getResolved(): ?Vehicle
    {
        return $this->resolved;
    }
}
