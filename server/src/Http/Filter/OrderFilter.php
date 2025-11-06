<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OrderFilter extends Filter
{
    public function queryForInternal()
    {
        $companyUuid = $this->request->session()->get('company');

        // apply company scope first for indexed filtering
        $this->builder->where('orders.company_uuid', $companyUuid);

        // replace ambiguous whereRelation with qualified whereHas to avoid alias clashes
        $this->builder->whereHas('payload', function ($payloadQuery) {
            $payloadQuery->where(function ($q) {
                $q->whereHas('waypoints', function ($w) {
                    $w->whereNotNull('waypoints.uuid');
                });
                $q->orWhereHas('pickup', function ($p) {
                    $p->whereNotNull('places.uuid');
                });
                $q->orWhereHas('dropoff', function ($d) {
                    $d->whereNotNull('places.uuid');
                });
            });
        });

        // ensure associated tracking data exists
        $this->builder->whereHas('trackingNumber', function ($q) {
            $q->select('uuid');
        });

        $this->builder->whereHas('trackingStatuses', function ($q) {
            $q->select('uuid');
        });

        // eager load main relationships to reduce N+1 overhead
        $this->builder->with([
            'payload.entities',
            'payload.waypoints',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'trackingNumber',
            'trackingStatuses',
            'driverAssigned',
        ]);
    }

    public function queryForPublic()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $query)
    {
        $this->builder->search($query, function ($builder, $query) {
            // also query for payload addresses
            $builder->orWhere(function ($builder) use ($query) {
                $builder->whereHas('payload', function ($builder) use ($query) {
                    $builder->where(function ($builder) use ($query) {
                        $builder->orWhereHas('pickup', function ($builder) use ($query) {
                            $builder->search($query);
                        });
                        $builder->orWhereHas('dropoff', function ($builder) use ($query) {
                            $builder->search($query);
                        });
                        $builder->orWhereHas('waypoints', function ($builder) use ($query) {
                            $builder->search($query);
                        });
                    });
                });
            });
        });
    }

    public function unassigned(bool $unassigned)
    {
        if ($unassigned) {
            $this->builder->where(
                function ($q) {
                    $q->whereDoesntHave('driverAssigned');
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
                    $q->whereHas('driverAssigned');
                    $q->whereNotIn('status', ['created', 'canceled', 'order_canceled', 'completed']);
                }
            );
        }
    }

    public function status(string|array $status)
    {
        // handle `active` alias status
        if ($status === 'active') {
            // active status is anything that is not these values
            $this->builder->whereNotIn('status', ['created', 'completed', 'expired', 'order_canceled', 'canceled', 'pending']);
            // remove the searchBuilder where clause
            $this->builder->removeWhereFromQuery('status', 'active');

            return;
        }

        $status = Utils::arrayFrom($status);
        if ($status) {
            $this->builder->whereIn('status', $status);
        }
    }

    public function customer(string $customer)
    {
        $this->builder->where(function ($query) use ($customer) {
            $query->where('customer_uuid', $customer);
            $query->orWhereHas('authenticatableCustomer', function ($query) use ($customer) {
                $query->where('user_uuid', $customer);
            });
        });
    }

    public function authenticatedCustomer(string $authenticatedCustomer)
    {
        $this->builder->whereHas('authenticatableCustomer', function ($query) use ($authenticatedCustomer) {
            $query->where('user_uuid', $authenticatedCustomer);
        });
    }

    public function facilitator(string $facilitator)
    {
        $this->builder->where('facilitator_uuid', $facilitator);
    }

    public function type(string $type)
    {
        $this->builder->where(function ($query) use ($type) {
            $query->where('type', $type);
            $query->orWhereHas('orderConfig', function ($query) use ($type) {
                $query->where('uuid', $type);
                $query->orWhere('public_id', $type);
                $query->orWhere('key', $type);
            });
        });
    }

    public function orderConfig(string $orderConfig)
    {
        $this->builder->whereHas('orderConfig', function ($query) use ($orderConfig) {
            $query->where('uuid', $orderConfig);
            $query->orWhere('public_id', $orderConfig);
            $query->orWhere('key', $orderConfig);
        });
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

    public function only($ids)
    {
        $this->builder->whereIn('public_id', $ids);
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

    public function vehicle(string $vehicle)
    {
        if (Str::isUuid($vehicle)) {
            $this->builder->where('vehicle_assigned_uuid', $vehicle);
        } else {
            $this->builder->where(function () use ($vehicle) {
                $this->builder->whereHas(
                    'vehicleAssigned',
                    function ($query) use ($vehicle) {
                        $query->where('public_id', $vehicle);
                        $query->orWhere('internal_id', $vehicle);
                    }
                );
            });
        }
    }

    public function driver(string $driver)
    {
        if (Str::isUuid($driver)) {
            $this->builder->where('driver_assigned_uuid', $driver);
        } else {
            $this->builder->where(function () use ($driver) {
                $this->builder->whereHas(
                    'driverAssigned',
                    function ($query) use ($driver) {
                        $query->where('public_id', $driver);
                        $query->orWhere('internal_id', $driver);
                    }
                );
                // REMOVED: include entities which can be assigned drivers
                // $this->builder->orWhereHas('payload.entities', function ($query) use ($driver) {
                //     $query->whereNotNull('driver_assigned_uuid');
                //     $query->whereHas(
                //         'driver',
                //         function ($query) use ($driver) {
                //             $query->where('public_id', $driver);
                //             $query->orWhere('internal_id', $driver);
                //         }
                //     );
                // });
            });
        }
    }

    public function driverAssigned(string $driver)
    {
        $this->driver($driver);
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
                $this->builder->select(['orders.*', 'contacts.name as customer_name']);
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

    public function exclude($exclude)
    {
        $exclude = Utils::arrayFrom($exclude);
        if (is_array($exclude)) {
            $isUuids = Arr::every($exclude, function ($id) {
                return Str::isUuid($id);
            });

            if ($isUuids) {
                $this->builder->whereNotIn('uuid', $exclude);
            } else {
                $this->builder->whereNotIn('public_id', $exclude);
            }
        }
    }

    public function bulkQuery($ids)
    {
        $ids     = Utils::arrayFrom($ids);
        $firstId = Arr::first($ids);
        if ($firstId) {
            $this->builder->where(function ($query) use ($ids, $firstId) {
                if (Utils::isPublicId($firstId)) {
                    return $query->whereIn('public_id', $ids);
                }

                if (Str::isUuid($firstId)) {
                    return $query->whereIn('uuid', $ids);
                }

                $query->whereIn('internal_id', $ids);
                $query->orWhereHas('trackingNumber', function ($query) use ($ids) {
                    $query->whereIn('tracking_number', $ids);
                });
            });
        }
    }

    public function createdAt($createdAt)
    {
        $createdAt = Utils::dateRange($createdAt);

        if (is_array($createdAt)) {
            $this->builder->whereBetween('created_at', $createdAt);
        } else {
            $this->builder->whereDate('created_at', $createdAt);
        }
    }

    public function updatedAt($updatedAt)
    {
        $updatedAt = Utils::dateRange($updatedAt);

        if (is_array($updatedAt)) {
            $this->builder->whereBetween('updated_at', $updatedAt);
        } else {
            $this->builder->whereDate('updated_at', $updatedAt);
        }
    }

    public function scheduledAt($scheduledAt)
    {
        $scheduledAt = Utils::dateRange($scheduledAt);

        if (is_array($scheduledAt)) {
            $this->builder->whereBetween('scheduled_at', $scheduledAt);
        } else {
            $this->builder->whereDate('scheduled_at', $scheduledAt);
        }
    }

    public function withoutDriver($without)
    {
        $without = Utils::castBoolean($without);
        if ($without) {
            $this->builder->whereNull('driver_assigned_uuid');
        }
    }
}
