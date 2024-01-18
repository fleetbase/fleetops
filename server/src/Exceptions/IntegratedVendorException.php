<?php

namespace Fleetbase\FleetOps\Exceptions;

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/**
 * Class IntegratedVendorException
 *
 * Custom exception class for IntegratedVendor-related exceptions.
 *
 * @package Fleetbase\FleetOps\Exceptions
 */
class IntegratedVendorException extends \Exception implements Responsable
{
    /**
     * @var IntegratedVendor|null The IntegratedVendor instance associated with the exception.
     */
    public ?IntegratedVendor $integratedVendor;

    /**
     * @var string The trigger method that caused the exception.
     */
    public string $triggerMethod;

    /**
     * IntegratedVendorException constructor.
     *
     * @param string $message The exception message.
     * @param IntegratedVendor|null $integratedVendor The IntegratedVendor instance associated with the exception.
     * @param string|null $triggerMethod The trigger method that caused the exception.
     * @param int $code The exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct(string $message = '', IntegratedVendor $integratedVendor = null, string $triggerMethod = null, int $code = 400, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->integratedVendor = $integratedVendor;
        $this->triggerMethod    = $triggerMethod;
    }

    /**
     * Get the response representing the exception.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toResponse($request)
    {
        $errorMessage = $this->getMessage();

        return new JsonResponse(
            [
                'errors' => [$errorMessage],
                'integratedVendorId' => data_get($this->integratedVendor, 'uuid')
            ],
            400
        );
    }
}
