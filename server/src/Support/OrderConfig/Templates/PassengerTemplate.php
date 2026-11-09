<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Templates;

use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTriggers;

/**
 * On-demand passenger trips, as in ride hailing or a taxi service. The customer books through a
 * customer app and the order is offered to one nearby driver at a time, for the vehicle type of
 * the service the customer chose. The driver accepting starts the order ("on the way"), then
 * arrives, starts the trip and completes it, or records a no-show. The price is worked out again
 * from the actual trip, and the customer is told about each step.
 *
 * Everything here is ordinary order config: change any of it on the company's copy.
 */
class PassengerTemplate extends Template
{
    protected string $key         = 'passenger';
    protected string $name        = 'Passenger';
    protected string $description = 'On-demand passenger trips: booked by the customer, offered to nearby drivers, priced from the actual trip.';

    public function attributes(): array
    {
        $notify = fn (string $title, string $message) => ['action' => 'notify_customer', 'params' => ['title' => $title, 'message' => $message]];

        return [
            'description' => $this->description,
            'tags'        => ['passenger', 'on-demand'],
            'entities'    => [],
            'meta'        => [
                'lifecycle' => [
                    'initial'            => 'created',
                    'completed'          => 'completed',
                    'canceled'           => 'canceled',
                    'terminal'           => ['no_show'],
                    'strict_transitions' => true,
                ],
            ],
            'policies' => [
                'dispatch' => [
                    'adhoc'    => ['customer_orders' => true, 'strategy' => 'sequential', 'offer_timeout' => 20, 'on_exhausted' => 'cancel'],
                    'matching' => ['vehicle_type' => true, 'exclude_busy_drivers' => true],
                ],
                'customer' => [
                    'ordering'     => ['payment_methods' => ['cash', 'card'], 'require_quote' => true, 'require_settlement' => ['card']],
                    'cancellation' => ['enabled' => true, 'allowed_until' => 'in_progress'],
                    'interactions' => ['chat' => true, 'rating' => true, 'share_tracking' => true],
                ],
                'pricing' => ['reprice_on_completion' => true, 'metering_starts_at' => 'in_progress'],
            ],
            'triggers' => [
                [
                    'event'   => OrderConfigTriggers::DRIVER_APPROACHING,
                    'options' => ['target' => 'pickup', 'distance' => 400],
                    'when'    => [['type' => 'and', 'conditions' => [['field' => 'status', 'operator' => 'equal', 'value' => 'started']]]],
                ] + $notify('Your driver is almost there', '{driver.first_name} is about a minute away.'),
                ['event' => OrderConfigTriggers::DISPATCH_OFFER_EXHAUSTED] + $notify('No driver found', 'No drivers are free right now. Try again in a few minutes.'),
                [
                    'event' => OrderConfigTriggers::ORDER_CANCELED,
                    // The customer was already told nobody was found.
                    'when'  => [['type' => 'and', 'conditions' => [['field' => 'dispatch_outcome', 'operator' => 'notEqual', 'value' => 'no_driver_found']]]],
                ] + $notify('Your trip was canceled', "You haven't been charged. You can book again."),
                ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'close_chat'],
            ],
            'flow' => [
                'created'     => static::activity('created', 'Trip requested', 'The customer requested a trip.', ['dispatched']),
                'dispatched'  => static::activity('dispatched', 'Finding a driver', 'Looking for a driver nearby.', ['started']),
                'started'     => static::activity('started', 'Driver on the way', 'The driver accepted and is on the way to the pickup.', ['arrived'], false, [
                    $notify('Your driver is on the way', '{driver.first_name} accepted your trip.'),
                    ['action' => 'open_chat'],
                ]),
                'arrived'     => static::activity('arrived', 'Driver has arrived', 'The driver is waiting at the pickup.', ['in_progress', 'no_show'], false, [
                    $notify('Your driver has arrived', '{driver.first_name} is waiting at the pickup point.'),
                ]),
                'in_progress' => static::activity('in_progress', 'Trip in progress', 'The passenger is on board.', ['completed'], false, [
                    $notify('Your trip has started', 'Sit back, you are on your way.'),
                ]),
                'completed'   => static::activity('completed', 'Trip completed', 'The passenger was dropped off.', [], true, [
                    $notify('You have arrived', 'Your trip is complete. See the price in the app.'),
                    ['action' => 'close_chat'],
                    // Run when Ledger is installed: bill the final price straight away.
                    ['action' => 'send_invoice'],
                ]),
                'no_show'     => static::activity('no_show', 'Passenger didn\'t show', 'The passenger didn\'t come to the pickup.', [], false, [
                    ['action' => 'close_chat'],
                ]),
            ],
        ];
    }

    public function customFields(): array
    {
        return [
            [
                'name'             => 'passengers',
                'label'            => 'Passengers',
                'type'             => 'input',
                'component'        => 'input',
                'default_value'    => '1',
                'required'         => false,
                'editable'         => true,
                'validation_rules' => ['nullable', 'integer', 'min:1', 'max:50'],
                'help_text'        => 'How many people are travelling.',
                'order'            => 0,
            ],
        ];
    }
}
