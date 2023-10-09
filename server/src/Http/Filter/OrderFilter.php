<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class OrderFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder
            ->where('company_uuid', $this->request->session()->get('company'))
            ->whereHas(
                'payload',
                function ($q) {
                    $q->where(
                        function ($q) {
                            $q->whereHas('waypoints');
                            $q->orWhereHas('pickup');
                            $q->orWhereHas('dropoff');
                        }
                    );
                    $q->with(['entities', 'waypoints', 'dropoff', 'pickup', 'return']);
                }
            )
            ->whereHas('trackingNumber')
            ->whereHas('trackingStatuses')
            ->with(
                [
                    'payload',
                    'trackingNumber',
                    'trackingStatuses',
                ]
            );
    }

    public function unassigned(bool $unassigned)
    {
        if ($unassigned) {
            $this->builder->where(
                function ($q) {
                    $q->whereNull('driver_assigned_uuid');
                    $q->whereNotIn('status', ['completed', 'canceled', 'expired']);
                }
            );
        }
    }

    public function tracking(string $tracking)
    {
        $this->builder->whereHas(
            'trackingNumber',
            function ($query) use ($tracking) {
                $query->where('tracking_number', $tracking);
            }
        );
    }

    public function active(bool $active = false)
    {
        if ($active) {
            $this->builder->where(
                function ($q) {
                    $q->whereNotIn('status', ['created', 'dispatched', 'pending', 'canceled', 'completed']);
                    $q->whereNotNull('driver_assigned_uuid');
                }
            );
        }
    }

    public function status(string $status)
    {
        // handle `active` alias status
        if ($status === 'active') {
            // active status is anything that is not these values
            $this->builder->whereNotIn('status', ['completed', 'expired', 'canceled']);
            // remove the searchBuilder where clause
            $this->builder->removeWhereFromQuery('status', 'active');
        } elseif (is_string($status)) {
            $this->builder->where('status', $status);
        }

        // if status is array
        if ($this->request->isArray('status')) {
            $this->builder->whereIn('status', $status);
        }
    }

    public function customer(string $customer)
    {
        $this->builder->where('customer_uuid', $customer);
    }

    public function facilitator(string $facilitator)
    {
        $this->builder->where('facilitator_uuid', $facilitator);
    }

    public function type(string $type)
    {
        $this->builder->where('type', $type);
    }

    public function payload(string $payload)
    {
        if (Str::isUuid($payload)) {
            $this->builder->where('payload_uuid', $payload);
        } else {
            $this->builder->whereHas(
                'payload',
                function ($query) use ($payload) {
                    $query->where('public_id', $payload);
                }
            );
        }
    }

    public function pickup(string $pickup)
    {
        $this->builder->whereHas(
            'payload',
            function ($query) use ($pickup) {
                if (Str::isUuid($pickup)) {
                    $query->where('pickup_uuid', $pickup);
                } else {
                    $query->whereHas(
                        'dropoff',
                        function ($query) use ($pickup) {
                            $query->where('public_id', $pickup);
                            $query->orWhere('internal_id', $pickup);
                        }
                    );
                }
            }
        );
    }

    public function dropoff(string $dropoff)
    {
        $this->builder->whereHas(
            'payload',
            function ($query) use ($dropoff) {
                if (Str::isUuid($dropoff)) {
                    $query->where('dropoff_uuid', $dropoff);
                } else {
                    $query->whereHas(
                        'dropoff',
                        function ($query) use ($dropoff) {
                            $query->where('public_id', $dropoff);
                            $query->orWhere('internal_id', $dropoff);
                        }
                    );
                }
            }
        );
    }

    public function return(string $return)
    {
        $this->builder->whereHas(
            'payload',
            function ($query) use ($return) {
                if (Str::isUuid($return)) {
                    $query->where('return_uuid', $return);
                } else {
                    $query->whereHas(
                        'return',
                        function ($query) use ($return) {
                            $query->where('public_id', $return);
                            $query->orWhere('internal_id', $return);
                        }
                    );
                }
            }
        );
    }

    public function driver(string $driver)
    {
        if (Str::isUuid($driver)) {
            $this->builder->where('driver_assigned_uuid', $driver);
        } else {
            $this->builder->whereHas(
                'driverAssigned',
                function ($query) use ($driver) {
                    $query->where('public_id', $driver);
                    $query->orWhere('internal_id', $driver);
                }
            );
            // include entities which can be assigned drivers
            $this->builder->orWhereHas('payload.entities', function ($query) use ($driver) {
                $query->whereNotNull('driver_assigned_uuid');
                $query->whereHas(
                    'driver',
                    function ($query) use ($driver) {
                        $query->where('public_id', $driver);
                        $query->orWhere('internal_id', $driver);
                    }
                );
            });
        }
    }

    public function sort(string $sort)
    {
        list($param, $direction) = Http::useSort($sort);

        switch ($param) {
            case 'tracking':
            case 'tracking_number':
                $this->builder->addSelect(['tns.tracking_number as tracking']);
                $this->builder->join('tracking_numbers as tns', 'tns.uuid', '=', 'orders.tracking_number_uuid')->orderBy('tracking', $direction);
                break;

            case 'customer':
                $this->builder->select(['orders.*', 'vendors.name as customer_name']);
                $this->builder->join('contacts', 'contacts.uuid', '=', 'orders.customer_uuid')->orderBy('customer_name', $direction);
                break;

            case 'facilitator':
                $this->builder->select(['orders.*', 'vendors.name as facilitator_name']);
                $this->builder->join('vendors', 'vendors.uuid', '=', 'orders.facilitator_uuid')->orderBy('facilitator_name', $direction);
                break;

            case 'pickup':
                $this->builder->select(['orders.*', 'places.name as pickup_name']);
                $this->builder->join('payloads', 'payloads.uuid', '=', 'orders.payload_uuid');
                $this->builder->join('places', 'places.uuid', '=', 'payloads.pickup_uuid')->orderBy('pickup_name', $direction);
                break;

            case 'dropoff':
                $this->builder->select(['orders.*', 'places.name as dropoff_name']);
                $this->builder->join('payloads', 'payloads.uuid', '=', 'orders.payload_uuid');
                $this->builder->join('places', 'places.uuid', '=', 'payloads.dropoff_uuid')->orderBy('dropoff_name', $direction);
                break;
        }

        return $this->builder;
    }
}
