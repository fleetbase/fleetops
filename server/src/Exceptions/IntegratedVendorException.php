<?php

namespace Fleetbase\FleetOps\Exceptions;

use Fleetbase\FleetOps\Models\IntegratedVendor;

class IntegratedVendorException extends \Exception
{
    public ?IntegratedVendor $integratedVendor;
    public string $triggerMethod;

    public function __construct(string $message = '', IntegratedVendor $integratedVendor = null, string $triggerMethod = null, int $code = 400, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->integratedVendor = $integratedVendor;
        $this->triggerMethod    = $triggerMethod;
    }
}
