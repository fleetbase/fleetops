<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    public function test()
    {
        $order = Order::where('public_id', 'order_vMZPkPB')->first();

        return $order->config()->nextActivity();
    }
}
