<?php

namespace Fleetbase\FleetOps\Exceptions;

/**
 * Thrown when a device that is already installed on one asset is attached to another
 * without being detached first. Attaching silently used to re-home the device, which
 * let an operator "steal" a tracker from a vehicle with no feedback.
 */
class DeviceAlreadyAttachedException extends \DomainException
{
    public static function for(?string $attachedToName): self
    {
        $target = $attachedToName ? ' to ' . $attachedToName : ' to another asset';

        return new self('Device is already attached' . $target . '. Detach it before attaching it elsewhere.');
    }
}
