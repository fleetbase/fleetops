<?php

$defaultFlow = [
    'created' => [
        'sequence' => 0,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Order dispatched',
                'details' => 'Order has been dispatched to driver',
                'code' => 'dispatched',
            ],
        ]
    ],
    'dispatched' => [
        'sequence' => 0,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Driver en-route',
                'details' => 'Driver en-route to location',
                'code' => 'driver_enroute',
            ],
        ]
    ],
    'driver_enroute' => [
        'sequence' => 0,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Order completed',
                'details' => 'Driver has completed order',
                'code' => 'completed',
            ],
        ]
    ],
    // waypoint drop order statuses
    'waypoint|dispatched' => [

        'sequence' => 0,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Driver en-route to {waypoint.ordinalIndex} waypoint',
                'details' => 'Driver en-route to {waypoint.address}',
                'code' => 'driver_enroute',
            ],
        ]
    ],
    'waypoint|driver_enroute' => [
        'sequence' => 0,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Order delivered for {waypoint.ordinalIndex} waypoint',
                'details' => 'Driver has completed delivery to {waypoint.address}',
                'code' => 'completed',
            ],
        ]
    ]
];

return [
    /*
    |--------------------------------------------------------------------------
    | API Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        // order events
        'order.created',
        'order.updated',
        'order.deleted',
        'order.dispatched',
        'order.dispatch_failed',
        'order.completed',
        'order.failed',
        'order.driver_assigned',
        'order.completed',

        // payload events
        'payload.created',
        'payload.updated',
        'payload.deleted',

        // entity events
        'entity.created',
        'entity.updated',
        'entity.deleted',
        'entity.driver_assigned',

        // driver events
        'driver.created',
        'driver.updated',
        'driver.deleted',
        'driver.assigned',
        // 'driver.entered_zone',
        // 'driver.exited_zone',

        // fleet events
        'fleet.created',
        'fleet.updated',
        'fleet.deleted',

        // purchase_rate events
        'purchase_rate.created',
        'purchase_rate.updated',
        'purchase_rate.deleted',

        // contact events
        'contact.created',
        'contact.updated',
        'contact.deleted',

        // place events
        'place.created',
        'place.updated',
        'place.deleted',

        // service_area events
        'service_area.created',
        'service_area.updated',
        'service_area.deleted',

        // service_quote events
        'service_quote.created',
        'service_quote.updated',
        'service_quote.deleted',

        // service_rate events
        'service_rate.created',
        'service_rate.updated',
        'service_rate.deleted',

        // tracking_number events
        'tracking_number.created',
        'tracking_number.updated',
        'tracking_number.deleted',

        // tracking_status events
        'tracking_status.created',
        'tracking_status.updated',
        'tracking_status.deleted',

        // vehicle events
        'vehicle.created',
        'vehicle.updated',
        'vehicle.deleted',

        // vendor events
        'vendor.created',
        'vendor.updated',
        'vendor.deleted',

        // zone events
        'zone.created',
        'zone.updated',
        'zone.deleted',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Resource Types
    |--------------------------------------------------------------------------
    */

    'types' => [
        'contact' => [
            [
                'name' => 'Customer',
                'key' => 'customer',
            ],
            [
                'name' => 'Contractor',
                'key' => 'contractor',
            ],
        ],

        'order' => [
            [
                'name' => 'Transport',
                'description' => 'Operational flow for standard A to B transport.',
                'key' => 'default',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $defaultFlow,
                ],
            ],
            [
                'name' => 'Parcel Delivery',
                'description' => 'Operational flow for standard A to B parcel delivery, with proof of delivery options.',
                'key' => 'parcel',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $defaultFlow,
                    'require_pod' => true,
                    'pod_method' => 'signature', // scan / signature
                ],
            ],
            [
                'name' => 'Passenger Transport',
                'description' => 'Operational flow for standard A to B passenger transport.',
                'key' => 'passenger',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $defaultFlow,
                ],
            ],
            [
                'name' => 'Task',
                'description' => 'Operational flow for custom task or service tasks.',
                'key' => 'task',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $defaultFlow,
                ],
            ],
            [
                'name' => 'Food Delivery',
                'description' => 'Operational flow for food delivery.',
                'key' => 'food',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $defaultFlow,
                ],
            ],
            [
                'name' => 'Ecommerce',
                'description' => 'Operational flow for ondemand ecommerce.',
                'key' => 'ecommerce',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $defaultFlow,
                ],
            ],
            [
                'name' => 'Haulage',
                'description' => 'Operational flow for freight container transport.',
                'key' => 'haul',
                'meta_type' => 'order_config',
                'meta' => [
                    'fields' => [
                        [
                            'label' => 'BL Number',
                            'key' => 'bl_number',
                            'type' => 'text',
                            'removable' => false,
                            'required' => true,
                            'group' => 'info',
                        ],
                        [
                            'label' => 'Job Type',
                            'key' => 'job_type',
                            'type' => 'select',
                            'options' => [
                                [
                                    'value' => 'import'
                                ],
                                [
                                    'value' => 'export'
                                ],
                                [
                                    'value' => 'one_way'
                                ]
                            ],
                            'removable' => false,
                            'required' => true,
                            'group' => 'info',
                        ],
                        [
                            'label' => 'DSTN Port',
                            'key' => 'dstn_port',
                            'type' => 'port',
                            'serialize' => 'model:port',
                            'removable' => false,
                            'required' => true,
                            'group' => 'info',
                        ],
                        [
                            'label' => 'Vessel',
                            'key' => 'vessel',
                            'type' => 'vessel',
                            'serialize' => 'model:vessel',
                            'removable' => false,
                            'required' => true,
                            'group' => 'vessel',
                        ],
                        [
                            'label' => 'Vessel ETA',
                            'key' => 'vessel_eta',
                            'type' => 'datetime',
                            'removable' => false,
                            'required' => true,
                            'group' => 'vessel',
                        ],
                        [
                            'label' => 'Vessel ETD',
                            'key' => 'vessel_etd',
                            'type' => 'datetime',
                            'removable' => false,
                            'required' => true,
                            'group' => 'vessel',
                        ],
                        [
                            'label' => 'Voyage Number',
                            'key' => 'voyage_number',
                            'type' => 'text',
                            'removable' => false,
                            'required' => true,
                            'group' => 'vessel',
                        ],
                    ],
                    'flow' => [
                        'created' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'if' => [['meta.job_type', '=', 'import']],
                                    'status' => 'In port',
                                    'details' => 'Driver has entered the port',
                                    'code' => 'driver_started',
                                ],
                                [
                                    'if' => [['meta.job_type', '=', 'export']],
                                    'status' => 'In depot',
                                    'details' => 'Driver has entered the depot',
                                    'code' => 'driver_started',
                                ],
                                [
                                    'if' => [['meta.job_type', '=', 'one_way']],
                                    'status' => 'Container picked up',
                                    'details' => 'Driver has picked up the container',
                                    'code' => 'driver_started',
                                ],
                            ],
                        ],
                        'driver_started' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'if' => [['meta.job_type', '=', 'import']],
                                    'status' => 'Out port',
                                    'details' => 'Laden container collected from the port',
                                    'code' => 'container_collected',
                                ],
                                [
                                    'if' => [['meta.job_type', '=', 'export']],
                                    'status' => 'Out depot',
                                    'details' => 'Driver has entered the depot',
                                    'code' => 'container_collected',
                                ],
                                [
                                    'if' => [['meta.job_type', '=', 'one_way']],
                                    'status' => 'Container dropped off',
                                    'details' => 'Driver has delivered the container',
                                    'code' => 'container_sent',
                                    'completed' => 1,
                                ],
                            ]
                        ],
                        'container_collected' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'if' => [['meta.job_type', '=', 'import']],
                                    'status' => 'With consignee',
                                    'details' => 'Container with consignee',
                                    'code' => 'container_unloading',
                                ],
                                [
                                    'if' => [['meta.job_type', '=', 'export']],
                                    'status' => 'With shipper',
                                    'details' => 'Container with shipper',
                                    'code' => 'container_loading',
                                ],
                            ]
                        ],
                        'container_unloading' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'status' => 'Out consignee',
                                    'details' => 'Container out of consignee',
                                    'code' => 'container_returning',
                                ],
                            ]
                        ],
                        'container_loading' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'status' => 'Out shipper',
                                    'details' => 'Container out of shipper',
                                    'code' => 'container_exporting',
                                ],
                            ]
                        ],
                        'container_returning' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'status' => 'Returned to depot',
                                    'details' => 'MT container returned to depot',
                                    'code' => 'container_returned',
                                    'completed' => 1,
                                ],
                            ]
                        ],
                        'container_exporting' => [
                            'sequence' => 0,
                            'color' => 'green',
                            'events' => [
                                [
                                    'status' => 'In port',
                                    'details' => 'Laden container sent for export',
                                    'code' => 'container_sent',
                                    'completed' => 1,
                                ],
                            ]
                        ],
                    ],
                    'entities' => [
                        [
                            'name' => '20 FT FLAT RACK',
                            'type' => 'container',
                            'description' => '20FR',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'FR',
                            ],
                        ],
                        [
                            'name' => '20 FT X 8\'6',
                            'type' => 'container',
                            'description' => '20GP',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'GP',
                            ],
                        ],
                        [
                            'name' => '20 FT X 9\'6',
                            'type' => 'container',
                            'description' => '20HC',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'HC',
                            ],
                        ],
                        [
                            'name' => '20 FT X 9\'6',
                            'type' => 'container',
                            'description' => '20HR',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'HR',
                            ],
                        ],
                        [
                            'name' => '20 FT X 9\'6',
                            'type' => 'container',
                            'description' => '20OH',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'OH',
                            ],
                        ],
                        [
                            'name' => '20 FT X 8\'6',
                            'type' => 'container',
                            'description' => '20OT',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'OT',
                            ],
                        ],
                        [
                            'name' => '20 FT X 8\'6',
                            'type' => 'container',
                            'description' => '20RF',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'RF',
                            ],
                        ],
                        [
                            'name' => '20 FT X 8\'6',
                            'type' => 'container',
                            'description' => '20TK',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'TK',
                            ],
                        ],
                        [
                            'name' => '40 FT X 8\'6',
                            'type' => 'container',
                            'description' => '40FR',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'FR',
                            ],
                        ],
                        [
                            'name' => '40 FT X 8\'6',
                            'type' => 'container',
                            'description' => '40GP',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'GP',
                            ],
                        ],
                        [
                            'name' => '40 FT X 9\'6',
                            'type' => 'container',
                            'description' => '40HC',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'HC',
                            ],
                        ],
                        [
                            'name' => '40 FT X 9\'6',
                            'type' => 'container',
                            'description' => '40HF',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'FR',
                            ],
                        ],
                        [
                            'name' => '40 FT X 9\'6',
                            'type' => 'container',
                            'description' => '40HR',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'RF',
                            ],
                        ],
                        [
                            'name' => '40 FT X 9\'6',
                            'type' => 'container',
                            'description' => '40HT',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'HT',
                            ],
                        ],
                        [
                            'name' => '40 FT X 8\'6',
                            'type' => 'container',
                            'description' => '40OT',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'OT',
                            ],
                        ],
                        [
                            'name' => 'PLAT FORM',
                            'type' => 'container',
                            'description' => '40PL',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'PL',
                            ],
                        ],
                        [
                            'name' => '40 FT X 8\'6',
                            'type' => 'container',
                            'description' => '40RF',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'RF',
                            ],
                        ],
                        [
                            'name' => '45 FT X 9\'611 GENERAL PURPOSE',
                            'type' => 'container',
                            'description' => '45GP',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'GP',
                            ],
                        ],
                        [
                            'name' => '45 FT X 9\'611 HIGH CUBE',
                            'type' => 'container',
                            'description' => '45HC',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'HC',
                            ],
                        ],
                        [
                            'name' => 'CHASSIS',
                            'type' => 'container',
                            'description' => 'CHASSIS',
                            'weight' => '16800',
                            'weight_unit' => 'kg',
                            'length' => '20',
                            'width' => '8.6',
                            'height' => '8.6',
                            'dimentions_unit' => 'ft',
                            'meta' => [
                                'type' => 'CHASSIS',
                            ],
                        ],
                    ],
                ],
            ],
        ],

        'entity' => [
            [
                'name' => 'Parcel',
                'key' => 'parcel',
                'fields' => [''],
            ],
            [
                'name' => 'Passenger',
                'key' => 'passenger',
                'fields' => [],
            ],
            [
                'name' => 'Task',
                'key' => 'task',
                'fields' => [],
            ],
            [
                'name' => 'Food',
                'key' => 'food',
                'fields' => [],
            ],
            [
                'name' => 'Product',
                'key' => 'product',
                'fields' => [],
            ],
            [
                'name' => 'Haulage',
                'key' => 'haul',
                'fields' => [],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Proof of Delivery Methods
    |--------------------------------------------------------------------------
    */

    'pod_methods' => 'scan,signature',

    /*
    |--------------------------------------------------------------------------
    | API/Webhook Versions
    |--------------------------------------------------------------------------
    */

    'versions' => ['2020-09-30'],
    'version' => '2020-09-30', // current version
];
