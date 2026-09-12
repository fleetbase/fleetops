<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\InspectionLinkPin;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * A public inspection link, as the console lists it.
 *
 * `state` is the computed one — active, expired, used or revoked — not the
 * stored `status`, because a link that has run out of time still says
 * `active` in the column. `url` is the link itself, present only for links
 * minted since the token has been kept.
 */
class InspectionLink extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $internal = Http::isInternalRequest();

        return [
            // Always the public id: it is what identifies a link back to the
            // revoke endpoint. `$this->id` is the table's auto-increment
            // column, which no lookup here resolves — a list built from it
            // could show links but never revoke one.
            'id'             => $this->public_id,
            'uuid'           => $this->when($internal, $this->uuid),
            'public_id'      => $this->when($internal, $this->public_id),
            'path'           => $this->path,
            'state'          => $this->state,
            'status'         => $this->status,
            'single_use'     => (bool) $this->single_use,
            'has_pin'        => $this->hasPin(),
            // Shown in the console, like the link, so a dispatcher can read it
            // out or send it again. Never on a public request.
            'pin'            => $this->when($internal, fn () => $this->pin),
            'pin_sent_via'   => $this->pin_sent_via,
            'pin_sent_at'    => $this->pin_sent_at,
            'pin_attempts'   => $this->when($internal, fn () => (int) $this->pin_attempts),
            'recipient'      => $this->when($internal, function () {
                $recipient = InspectionLinkPin::recipientFor($this->resource);

                return $recipient ? ['name' => $recipient->name] : null;
            }),
            'can_send_pin'   => $this->when($internal, function () {
                $recipient = InspectionLinkPin::recipientFor($this->resource);

                return [
                    'email' => InspectionLinkPin::unavailableReason($recipient, 'email') === null,
                    'sms'   => InspectionLinkPin::unavailableReason($recipient, 'sms') === null,
                ];
            }),
            'assignee'       => $this->assignee ? [
                'id'   => $this->assignee->public_id,
                'name' => $this->assignee->name,
            ] : null,
            'driver'         => $this->driver ? [
                'id'   => $this->driver->public_id,
                'name' => $this->driver->name,
            ] : null,
            'vehicle'        => $this->vehicle ? [
                'id'   => $this->vehicle->public_id,
                'name' => $this->vehicle->display_name ?? $this->vehicle->name,
            ] : null,
            'created_by'     => $this->createdBy ? [
                'id'   => $this->createdBy->public_id,
                'name' => $this->createdBy->name,
            ] : null,
            'expires_at'     => $this->expires_at,
            'last_viewed_at' => $this->last_viewed_at,
            'used_at'        => $this->used_at,
            'created_at'     => $this->created_at,
        ];
    }
}
