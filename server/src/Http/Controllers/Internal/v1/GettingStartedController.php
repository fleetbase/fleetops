<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\GettingStarted;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GettingStartedController extends Controller
{
    public function status(Request $request)
    {
        return $this->jsonResponse(
            $this->getStatusForCompany($request->user()->company)
        );
    }

    protected function getStatusForCompany($company): array
    {
        return GettingStarted::forCompany($company)->get();
    }

    protected function jsonResponse(array $payload)
    {
        return response()->json($payload);
    }
}
