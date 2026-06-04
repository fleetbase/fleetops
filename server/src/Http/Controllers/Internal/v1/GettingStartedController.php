<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\GettingStarted;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GettingStartedController extends Controller
{
    public function status(Request $request)
    {
        return response()->json(
            GettingStarted::forCompany($request->user()->company)->get()
        );
    }
}
