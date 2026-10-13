<?php

namespace Fleetbase\FleetOps\Exceptions;

/**
 * Thrown when an order edit touches a field its current status locks.
 *
 * Carries the refused fields with the reason for each, so a controller can
 * answer 422 with `{ error, locked: { field: reason } }`.
 */
class OrderEditLockedException extends \Exception
{
    /**
     * @param array<string, string> $locked the refused fields and why each is locked
     */
    public function __construct(public array $locked = [], string $message = '', int $code = 422, ?\Throwable $previous = null)
    {
        if ($message === '') {
            $message = $locked ? implode(' ', array_values(array_unique($locked))) : 'This order can no longer be edited.';
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * The 422 response body: the message plus the refused fields.
     */
    public function toResponseData(): array
    {
        return ['locked' => $this->locked];
    }
}
