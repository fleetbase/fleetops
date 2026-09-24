<?php

namespace Fleetbase\FleetOps\Exceptions;

/**
 * Thrown when a driver, contact or customer profile is given an email or phone
 * that belongs to an account it cannot be linked to.
 */
class ProfileIdentityConflictException extends \Exception
{
    /**
     * The profile field the conflict was found on (`email` or `phone`).
     */
    protected string $field;

    public function __construct(string $message, string $field = 'email')
    {
        parent::__construct($message);
        $this->field = $field;
    }

    public function getField(): string
    {
        return $this->field;
    }

    /**
     * The conflict keyed by field, in the shape of a validation error bag.
     */
    public function getErrors(): array
    {
        return [$this->field => [$this->getMessage()]];
    }
}
