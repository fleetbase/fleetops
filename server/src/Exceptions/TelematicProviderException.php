<?php

namespace Fleetbase\FleetOps\Exceptions;

/**
 * Class TelematicProviderException.
 *
 * Base exception for provider-related errors.
 */
class TelematicProviderException extends \Exception
{
    protected array $context;

    public function __construct(string $message = '', array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    public function context(): array
    {
        return $this->context;
    }
}
