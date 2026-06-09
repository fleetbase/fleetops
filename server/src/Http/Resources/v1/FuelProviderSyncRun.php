<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class FuelProviderSyncRun extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'fuel_provider_connection_uuid' => $this->when(Http::isInternalRequest(), $this->fuel_provider_connection_uuid),
            'provider'                      => $this->provider,
            'status'                        => $this->status,
            'from'                          => $this->from,
            'to'                            => $this->to,
            'imported'                      => $this->imported,
            'matched'                       => $this->matched,
            'unmatched'                     => $this->unmatched,
            'fuel_reports_created'          => $this->fuel_reports_created,
            'liters'                        => $this->liters,
            'amount'                        => $this->amount,
            'started_at'                    => $this->started_at,
            'finished_at'                   => $this->finished_at,
            'error'                         => $this->error,
            'summary'                       => $this->summary,
            'meta'                          => $this->meta,
            'updated_at'                    => $this->updated_at,
            'created_at'                    => $this->created_at,
        ];
    }
}
