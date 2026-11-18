<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\Push\Concerns\PushesToApps;
use Fleetbase\Push\Contracts\SendsToPushApps;
use Illuminate\Notifications\Notification;

/**
 * The test push sent from Settings → Push apps, to check an app's credentials.
 */
class PushAppTest extends Notification implements SendsToPushApps
{
    use PushesToApps;

    public array $data = ['type' => 'push_app_test'];

    public function __construct(public string $title, public string $message)
    {
    }
}
