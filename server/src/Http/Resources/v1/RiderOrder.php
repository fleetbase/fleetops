<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

/**
 * An order as its customer sees it in a rider app: the order, plus its fare, the rider's rating,
 * how it was paid and whether it ended with no driver found.
 */
class RiderOrder extends Order
{
    public function toArray($request): array
    {
        $rating = $this->resource->driverRating;

        return array_merge(parent::toArray($request), [
            'fare'             => $this->resource->fare(),
            'rating'           => $rating ? ['stars' => $rating->stars, 'comment' => $rating->comment] : null,
            'payment_method'   => data_get($this->resource, 'payload.payment_method'),
            'paid_at'          => $this->paid_at,
            'dispatch_outcome' => $this->resource->dispatchOutcome(),
        ]);
    }
}
