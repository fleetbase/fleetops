<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Support\NotificationPreferences;

/**
 * A driver's own choice of what the Navigator app rings them about.
 *
 * The same notifications also go to customers and to people in the console;
 * only a driver's copy is governed by the driver's switches.
 */
class DriverAlerts
{
    public static function channels($notifiable, string $category, array $channels): array
    {
        if (!$notifiable instanceof Driver || !class_exists(NotificationPreferences::class)) {
            return $channels;
        }

        return NotificationPreferences::channels($notifiable, $category, $channels);
    }
}
