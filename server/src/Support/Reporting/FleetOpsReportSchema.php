<?php

namespace Fleetbase\FleetOps\Support\Reporting;

use Fleetbase\Support\Reporting\Contracts\ReportSchema;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;

class FleetOpsReportSchema implements ReportSchema
{
    /**
     * Register tables and columns for report generation.
     */
    public function registerReportSchema(ReportSchemaRegistry $registry): void
    {
        // Register Orders table
        $registry->registerTable($this->createOrdersTable());

        // Register Drivers table
        $registry->registerTable($this->createDriversTable());

        // Register Vehicles table
        $registry->registerTable($this->createVehiclesTable());

        // Assets includes first-class trailers; filter asset_class = trailer for trailer reports.
        $registry->registerTable($this->createAssetsTable());

        // Register Places table
        $registry->registerTable($this->createPlacesTable());

        // Register Contacts table
        $registry->registerTable($this->createContactsTable());

        // Register Vendors table
        $registry->registerTable($this->createVendorsTable());

        // Register Fuel Reports table
        $registry->registerTable($this->createFuelReportsTable());

        // Register Maintenance tables
        $registry->registerTable($this->createWorkOrdersTable());
        $registry->registerTable($this->createMaintenancesTable());
        $registry->registerTable($this->createInspectionSubmissionsTable());
    }

    /**
     * Create the Orders table definition.
     *
     * Money in orders is stored in the currency's smallest unit (e.g. cents): storefront orders
     * keep their totals in `meta` (`subtotal`, `delivery_fee`, `tip`, `total`) and each line
     * item is an entity of the order's payload with `meta.quantity` and `meta.subtotal`.
     *
     * Selecting line item columns (Payload Items) returns one row per item, so order-level
     * sums over item rows repeat each order; count orders with Total Orders (a distinct count).
     */
    protected function createOrdersTable(): Table
    {
        return $this->softDeletes(Table::make('orders'))
            ->label('Orders')
            ->description('Delivery and service orders, with their payload items, tracking, assignment and payment')
            ->category('Operations')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at']) // Hide foreign keys and system columns
            ->maxRows(50000)
            ->cacheTtl(3600)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Order ID')
                    ->description('Public order identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('internal_id', 'string')
                    ->label('Internal ID')
                    ->description('Internal order reference')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('status', 'string')
                    ->label('Status')
                    ->description('Current order status')
                    ->filterable()
                    ->sortable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        $labels = [
                            'created'         => 'Created',
                            'preparing'       => 'Preparing',
                            'dispatched'      => 'Dispatched',
                            'driver_assigned' => 'Driver Assigned',
                            'in_progress'     => 'In Progress',
                            'completed'       => 'Completed',
                            'canceled'        => 'Canceled',
                        ];

                        return $labels[$value] ?? ucfirst($value);
                    }),

                Column::make('type', 'string')
                    ->label('Order Type')
                    ->description('Type of order service, e.g. transport or storefront')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('customer_type', 'string')
                    ->label('Customer Kind')
                    ->description('Whether the customer is a contact or a vendor')
                    ->filterable()
                    ->aggregatable(),

                Column::make('facilitator_type', 'string')
                    ->label('Facilitator Kind')
                    ->description('Whether the facilitator is a vendor or an integrated vendor')
                    ->filterable()
                    ->aggregatable(),

                Column::make('scheduled_at', 'datetime')
                    ->label('Scheduled At')
                    ->description('When the order is scheduled for')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('dispatched', 'boolean')
                    ->label('Dispatched')
                    ->description('Whether the order has been dispatched')
                    ->filterable()
                    ->aggregatable(),

                Column::make('dispatched_at', 'datetime')
                    ->label('Dispatched At')
                    ->description('When the order was dispatched')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('started', 'boolean')
                    ->label('Started')
                    ->description('Whether the order has been started')
                    ->filterable()
                    ->aggregatable(),

                Column::make('started_at', 'datetime')
                    ->label('Started At')
                    ->description('When the order was started')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('time_window_start', 'datetime')
                    ->label('Time Window Start')
                    ->description('Earliest time the order may be serviced')
                    ->filterable()
                    ->sortable(),

                Column::make('time_window_end', 'datetime')
                    ->label('Time Window End')
                    ->description('Latest time the order may be serviced')
                    ->filterable()
                    ->sortable(),

                Column::make('distance', 'integer')
                    ->label('Distance (m)')
                    ->description('Route distance for the order in meters')
                    ->filterable()
                    ->aggregatable()
                    ->sortable(),

                Column::make('time', 'integer')
                    ->label('Duration (s)')
                    ->description('Estimated route duration in seconds')
                    ->filterable()
                    ->aggregatable()
                    ->sortable(),

                Column::make('adhoc', 'boolean')
                    ->label('Ad Hoc')
                    ->description('Whether this is an ad hoc order')
                    ->filterable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        return $value ? 'Yes' : 'No';
                    }),

                Column::make('adhoc_distance', 'integer')
                    ->label('Ad Hoc Distance (m)')
                    ->description('Radius in meters used to offer an ad hoc order to drivers')
                    ->filterable()
                    ->sortable(),

                Column::make('pod_required', 'boolean')
                    ->label('POD Required')
                    ->description('Whether proof of delivery is required')
                    ->filterable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        return $value ? 'Yes' : 'No';
                    }),

                Column::make('pod_method', 'string')
                    ->label('POD Method')
                    ->description('Proof of delivery method, e.g. scan, signature or photo')
                    ->filterable()
                    ->aggregatable(),

                Column::make('is_route_optimized', 'boolean')
                    ->label('Route Optimized')
                    ->description('Whether the route was optimized')
                    ->filterable()
                    ->aggregatable(),

                Column::make('orchestrator_priority', 'integer')
                    ->label('Priority')
                    ->description('Dispatch priority used by the orchestrator')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('notes', 'string')
                    ->label('Notes')
                    ->description('Order notes')
                    ->searchable()
                    ->filterable(),

                Column::make('created_at', 'datetime')
                    ->label('Created At')
                    ->description('When the order was created')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('updated_at', 'datetime')
                    ->label('Updated At')
                    ->description('When the order was last updated')
                    ->filterable()
                    ->sortable(),

                Column::make('meta', 'json')
                    ->label('Metadata')
                    ->description('Order metadata and custom fields; read a key with JSON_UNQUOTE(JSON_EXTRACT(meta, \'$.key\'))')
                    ->searchable()
                    ->filterable(),

                // Storefront orders keep their checkout totals in meta, in the currency's smallest unit.
                $this->expression('storefront', "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.storefront'))", 'string')
                    ->label('Storefront')
                    ->description('Name of the storefront the order was placed in')
                    ->filterable()
                    ->sortable(),

                $this->expression('order_currency', "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.currency'))", 'string')
                    ->label('Order Currency')
                    ->description('Currency of the storefront order totals')
                    ->filterable()
                    ->sortable(),

                $this->expression('order_subtotal', $this->jsonAmount('meta', 'subtotal'), 'decimal')
                    ->label('Order Subtotal (minor units)')
                    ->description('Storefront order subtotal in the currency\'s smallest unit (e.g. cents)')
                    ->filterable()
                    ->sortable(),

                $this->expression('order_delivery_fee', $this->jsonAmount('meta', 'delivery_fee'), 'decimal')
                    ->label('Delivery Fee (minor units)')
                    ->description('Storefront delivery fee in the currency\'s smallest unit (e.g. cents)')
                    ->filterable()
                    ->sortable(),

                $this->expression('order_tip', $this->jsonAmount('meta', 'tip'), 'decimal')
                    ->label('Tip (minor units)')
                    ->description('Storefront tip in the currency\'s smallest unit (e.g. cents)')
                    ->filterable()
                    ->sortable(),

                $this->expression('order_total', $this->jsonAmount('meta', 'total'), 'decimal')
                    ->label('Order Total (minor units)')
                    ->description('Storefront order total in the currency\'s smallest unit (e.g. cents)')
                    ->filterable()
                    ->sortable(),
            ])
            ->computedColumns([
                // Distinct, so the count stays right when payload items are selected too.
                Column::count('total_orders', 'DISTINCT id')
                    ->label('Total Orders')
                    ->description('Number of orders'),

                Column::computed('completed_orders', "COUNT(DISTINCT CASE WHEN status = 'completed' THEN id END)", 'integer')
                    ->label('Completed Orders')
                    ->description('Number of completed orders'),

                Column::computed('canceled_orders', "COUNT(DISTINCT CASE WHEN status = 'canceled' THEN id END)", 'integer')
                    ->label('Canceled Orders')
                    ->description('Number of canceled orders'),

                Column::sum('total_distance', 'distance')
                    ->label('Total Distance (m)')
                    ->description('Sum of all order distances'),

                Column::avg('average_distance', 'distance')
                    ->label('Average Distance (m)')
                    ->description('Average distance per order'),

                Column::sum('total_time', 'time')
                    ->label('Total Duration (s)')
                    ->description('Sum of all order durations'),

                Column::avg('average_time', 'time')
                    ->label('Average Duration (s)')
                    ->description('Average duration per order'),

                Column::sum('total_order_amount', 'order_total')
                    ->label('Order Total Sum (minor units)')
                    ->description('Sum of storefront order totals'),

                Column::avg('average_order_amount', 'order_total')
                    ->label('Average Order Total (minor units)')
                    ->description('Average storefront order total'),

                Column::sum('total_delivery_fees', 'order_delivery_fee')
                    ->label('Delivery Fees Sum (minor units)')
                    ->description('Sum of storefront delivery fees'),

                Column::sum('total_tips', 'order_tip')
                    ->label('Tips Sum (minor units)')
                    ->description('Sum of storefront tips'),

                Column::sum('total_transaction_amount', 'transaction.amount')
                    ->label('Transaction Amount Sum (minor units)')
                    ->description('Sum of the orders\' transaction amounts'),

                Column::avg('average_transaction_amount', 'transaction.amount')
                    ->label('Average Transaction Amount (minor units)')
                    ->description('Average transaction amount per order'),

                Column::count('orders_with_transactions', 'DISTINCT transaction_uuid')
                    ->label('Orders with Transactions')
                    ->description('Number of orders that have a transaction'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('tracking_number', 'tracking_numbers')
                    ->label('Tracking')
                    ->description('The order\'s tracking number and its latest tracking status')
                    ->localKey('tracking_number_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('tracking_number', 'string')->label('Number')->description('Tracking number'),
                        Column::make('public_id', 'string')->label('ID')->description('Tracking number record identifier'),
                        Column::make('region', 'string')->label('Region'),
                    ])
                    ->with([
                        Relationship::hasAutoJoin('status', 'tracking_statuses')
                            ->label('Tracking Status')
                            ->localKey('status_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('status', 'string')->label('Status'),
                                Column::make('code', 'string')->label('Code'),
                                Column::make('details', 'string')->label('Details'),
                                Column::make('complete', 'boolean')->label('Complete'),
                                Column::make('city', 'string')->label('City'),
                                Column::make('province', 'string')->label('Province'),
                                Column::make('country', 'string')->label('Country'),
                                Column::make('created_at', 'datetime')->label('Updated At'),
                            ]),
                    ]),

                Relationship::hasAutoJoin('order_config', 'order_configs')
                    ->label('Order Config')
                    ->description('The order configuration (order type) the order follows')
                    ->localKey('order_config_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Name'),
                        Column::make('key', 'string')->label('Key'),
                        Column::make('namespace', 'string')->label('Namespace'),
                    ]),

                Relationship::hasAutoJoin('payload', 'payloads')
                    ->label('Payload')
                    ->localKey('payload_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Payload identifier'),
                        Column::make('type', 'string')->label('Type'),
                        Column::make('payment_method', 'string')->label('Payment Method'),
                        Column::make('cod_amount', 'integer')->label('COD Amount (minor units)')->description('Cash on delivery amount in the currency\'s smallest unit'),
                        Column::make('cod_currency', 'string')->label('COD Currency'),
                        Column::make('cod_payment_method', 'string')->label('COD Payment Method'),
                        Column::make('provider', 'string')->label('Provider'),
                    ])
                    ->with([
                        Relationship::hasAutoJoin('pickup', 'places')
                            ->label('Pickup')
                            ->localKey('pickup_uuid')
                            ->foreignKey('uuid')
                            ->columns($this->placeColumns()),

                        Relationship::hasAutoJoin('dropoff', 'places')
                            ->label('Dropoff')
                            ->localKey('dropoff_uuid')
                            ->foreignKey('uuid')
                            ->columns($this->placeColumns()),

                        Relationship::hasAutoJoin('return', 'places')
                            ->label('Return')
                            ->localKey('return_uuid')
                            ->foreignKey('uuid')
                            ->columns($this->placeColumns()),

                        // One row per item: the goods, parcels or storefront products in the order.
                        $this->softDeletes(Relationship::hasAutoJoin('entities', 'entities'))
                            ->label('Item')
                            ->description('Items carried by the order (storefront products, parcels, goods); one row per item')
                            ->localKey('uuid')
                            ->foreignKey('payload_uuid')
                            ->columns([
                                Column::make('public_id', 'string')->label('ID')->description('Item identifier'),
                                Column::make('internal_id', 'string')->label('Internal ID')->description('Internal reference; the product ID for storefront items'),
                                Column::make('name', 'string')->label('Name')->aggregatable(),
                                Column::make('type', 'string')->label('Type')->aggregatable(),
                                Column::make('description', 'string')->label('Description'),
                                Column::make('sku', 'string')->label('SKU')->aggregatable(),
                                Column::make('currency', 'string')->label('Currency'),
                                Column::make('price', 'decimal')->label('Price (minor units)')->description('Unit price in the currency\'s smallest unit'),
                                Column::make('sale_price', 'decimal')->label('Sale Price (minor units)')->description('Unit sale price in the currency\'s smallest unit'),
                                Column::make('declared_value', 'integer')->label('Declared Value (minor units)'),
                                Column::make('weight', 'decimal')->label('Weight'),
                                Column::make('weight_unit', 'string')->label('Weight Unit'),
                                Column::make('length', 'decimal')->label('Length'),
                                Column::make('width', 'decimal')->label('Width'),
                                Column::make('height', 'decimal')->label('Height'),
                                Column::make('dimensions_unit', 'string')->label('Dimensions Unit'),
                                Column::make('barcode', 'string')->label('Barcode'),
                                Column::make('meta', 'json')->label('Metadata'),
                                Column::make('created_at', 'datetime')->label('Created At'),
                                $this->expression('product_id', "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.product_id'))", 'string')
                                    ->label('Product ID')
                                    ->description('Storefront product the item was ordered as'),
                                $this->expression('quantity', "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.quantity')) AS DECIMAL(15,2)), 1)", 'decimal')
                                    ->label('Quantity')
                                    ->description('Quantity ordered; 1 when the item records none'),
                                $this->expression('line_total', $this->jsonAmount('meta', 'subtotal'), 'decimal')
                                    ->label('Line Total (minor units)')
                                    ->description('Storefront line subtotal (price x quantity, with variants and add-ons) in the currency\'s smallest unit'),
                            ])
                            ->with([
                                Relationship::hasAutoJoin('destination', 'places')
                                    ->label('Destination')
                                    ->localKey('destination_uuid')
                                    ->foreignKey('uuid')
                                    ->columns($this->placeColumns()),
                            ]),
                    ]),

                Relationship::hasAutoJoin('driver_assigned', 'drivers')
                    ->label('Driver')
                    ->localKey('driver_assigned_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Driver identifier'),
                        Column::make('internal_id', 'string')->label('Internal ID'),
                        Column::make('drivers_license_number', 'string')->label('License Number'),
                        Column::make('country', 'string')->label('Country'),
                        Column::make('city', 'string')->label('City'),
                        Column::make('status', 'string')->label('Status'),
                        Column::make('online', 'boolean')->label('Online'),
                    ])->with([
                        Relationship::hasAutoJoin('user', 'users')
                            ->label('Driver')
                            ->localKey('user_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('name', 'string')->label('Name'),
                                Column::make('email', 'string')->label('Email'),
                                Column::make('phone', 'string')->label('Phone'),
                            ]),
                    ]),

                Relationship::hasAutoJoin('vehicle_assigned', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('vehicle_assigned_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Vehicle identifier'),
                        Column::make('internal_id', 'string')->label('Internal ID'),
                        Column::make('name', 'string')->label('Name'),
                        Column::make('make', 'string')->label('Make'),
                        Column::make('model', 'string')->label('Model'),
                        Column::make('year', 'integer')->label('Year'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                        Column::make('vin', 'string')->label('VIN'),
                        Column::make('serial_number', 'string')->label('Serial Number'),
                        Column::make('call_sign', 'string')->label('Call Sign'),
                    ]),

                Relationship::hasAutoJoin('customer', 'contacts')
                    ->label('Customer')
                    ->description('The customer when it is a contact')
                    ->localKey('customer_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Customer identifier'),
                        Column::make('internal_id', 'string')->label('Internal ID'),
                        Column::make('name', 'string')->label('Name'),
                        Column::make('title', 'string')->label('Title'),
                        Column::make('email', 'string')->label('Email'),
                        Column::make('phone', 'string')->label('Phone'),
                        Column::make('type', 'string')->label('Type'),
                    ]),

                Relationship::hasAutoJoin('customer_vendor', 'vendors')
                    ->label('Customer Vendor')
                    ->description('The customer when it is a vendor')
                    ->localKey('customer_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Vendor identifier'),
                        Column::make('internal_id', 'string')->label('Internal ID'),
                        Column::make('name', 'string')->label('Name'),
                        Column::make('email', 'string')->label('Email'),
                        Column::make('phone', 'string')->label('Phone'),
                        Column::make('type', 'string')->label('Type'),
                    ]),

                Relationship::hasAutoJoin('facilitator', 'vendors')
                    ->label('Facilitator')
                    ->localKey('facilitator_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Facilitator identifier'),
                        Column::make('internal_id', 'string')->label('Internal ID'),
                        Column::make('name', 'string')->label('Name'),
                        Column::make('email', 'string')->label('Email'),
                        Column::make('phone', 'string')->label('Phone'),
                        Column::make('type', 'string')->label('Type'),
                    ]),

                Relationship::hasAutoJoin('created_by', 'users')
                    ->label('Created By')
                    ->localKey('created_by_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Name'),
                        Column::make('email', 'string')->label('Email'),
                    ]),

                Relationship::hasAutoJoin('purchase_rate', 'purchase_rates')
                    ->label('Purchase Rate')
                    ->description('The service quote purchased for the order')
                    ->localKey('purchase_rate_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('ID')->description('Purchase rate identifier'),
                        Column::make('status', 'string')->label('Status'),
                    ])
                    ->with([
                        Relationship::hasAutoJoin('service_quote', 'service_quotes')
                            ->label('Quote')
                            ->localKey('service_quote_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('public_id', 'string')->label('ID')->description('Service quote identifier'),
                                Column::make('amount', 'integer')->label('Amount (minor units)')->description('Quoted delivery price in the currency\'s smallest unit'),
                                Column::make('currency', 'string')->label('Currency'),
                            ]),
                    ]),

                Relationship::hasAutoJoin('transaction', 'transactions')
                    ->label('Transaction')
                    ->localKey('transaction_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')
                            ->label('Transaction ID')
                            ->description('Public transaction identifier')
                            ->searchable()
                            ->filterable()
                            ->sortable(),

                        Column::make('gateway_transaction_id', 'string')
                            ->label('Gateway Transaction ID')
                            ->description('Transaction ID from payment gateway')
                            ->searchable()
                            ->filterable()
                            ->sortable(),

                        Column::make('gateway', 'string')
                            ->label('Payment Gateway')
                            ->description('Payment gateway used for transaction')
                            ->filterable()
                            ->aggregatable(),

                        Column::make('payment_method', 'string')
                            ->label('Payment Method')
                            ->description('Payment method used, e.g. card or cash')
                            ->filterable()
                            ->aggregatable(),

                        Column::make('amount', 'integer')
                            ->label('Amount (minor units)')
                            ->description('Transaction amount in the currency\'s smallest unit (e.g. cents)')
                            ->aggregatable()
                            ->sortable(),

                        Column::make('fee_amount', 'integer')
                            ->label('Fee Amount (minor units)')
                            ->description('Gateway fee in the currency\'s smallest unit')
                            ->aggregatable()
                            ->sortable(),

                        Column::make('tax_amount', 'integer')
                            ->label('Tax Amount (minor units)')
                            ->description('Tax in the currency\'s smallest unit')
                            ->aggregatable()
                            ->sortable(),

                        Column::make('net_amount', 'integer')
                            ->label('Net Amount (minor units)')
                            ->description('Amount after fees in the currency\'s smallest unit')
                            ->aggregatable()
                            ->sortable(),

                        Column::make('currency', 'string')
                            ->label('Currency')
                            ->description('Transaction currency code')
                            ->filterable()
                            ->aggregatable(),

                        Column::make('description', 'string')
                            ->label('Description')
                            ->description('Transaction description')
                            ->searchable()
                            ->filterable(),

                        Column::make('type', 'string')
                            ->label('Transaction Type')
                            ->description('Type of transaction')
                            ->filterable()
                            ->aggregatable(),

                        Column::make('status', 'string')
                            ->label('Transaction Status')
                            ->description('Current transaction status')
                            ->filterable()
                            ->aggregatable()
                            ->transformer(function ($value) {
                                $labels = [
                                    'pending'   => 'Pending',
                                    'completed' => 'Completed',
                                    'failed'    => 'Failed',
                                    'refunded'  => 'Refunded',
                                    'canceled'  => 'Canceled',
                                ];

                                return $labels[$value] ?? ucfirst($value);
                            }),

                        Column::make('settlement_status', 'string')
                            ->label('Settlement Status')
                            ->description('Whether the transaction has settled')
                            ->filterable()
                            ->aggregatable(),

                        Column::make('created_at', 'datetime')
                            ->label('Transaction Date')
                            ->description('When the transaction was created')
                            ->filterable()
                            ->sortable()
                            ->aggregatable(),
                    ])
                    ->with([
                        // Nested relationship for transaction items
                        $this->softDeletes(Relationship::hasAutoJoin('items', 'transaction_items'))
                            ->label('Transaction Items')
                            ->localKey('uuid')
                            ->foreignKey('transaction_uuid')
                            ->columns([
                                Column::make('description', 'string')
                                    ->label('Item Description')
                                    ->description('Line item description')
                                    ->searchable()
                                    ->filterable(),

                                Column::make('quantity', 'integer')
                                    ->label('Item Quantity')
                                    ->description('Line item quantity')
                                    ->aggregatable()
                                    ->sortable(),

                                Column::make('unit_price', 'integer')
                                    ->label('Item Unit Price (minor units)')
                                    ->description('Line item unit price')
                                    ->aggregatable()
                                    ->sortable(),

                                Column::make('amount', 'integer')
                                    ->label('Item Amount (minor units)')
                                    ->description('Line item amount')
                                    ->aggregatable()
                                    ->sortable(),

                                Column::make('currency', 'string')
                                    ->label('Item Currency')
                                    ->description('Line item currency code')
                                    ->filterable(),

                                Column::make('details', 'string')
                                    ->label('Item Details')
                                    ->description('Detailed description of the line item')
                                    ->searchable()
                                    ->filterable(),

                                Column::make('code', 'string')
                                    ->label('Item Code')
                                    ->description('Item or SKU code')
                                    ->searchable()
                                    ->filterable()
                                    ->sortable(),
                            ]),
                    ]),
            ]);
    }

    /**
     * Create the Drivers table definition.
     */
    protected function createDriversTable(): Table
    {
        return $this->softDeletes(Table::make('drivers'))
            ->label('Drivers')
            ->description('Fleet drivers and personnel')
            ->category('Personnel')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta'])
            ->maxRows(10000)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Driver ID')
                    ->description('Public driver identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('internal_id', 'string')
                    ->label('Internal ID')
                    ->description('Internal driver reference')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('drivers_license_number', 'string')
                    ->label('License Number')
                    ->description('Driver license number')
                    ->searchable()
                    ->filterable(),

                Column::make('license_expiry', 'date')
                    ->label('License Expiry')
                    ->description('When the driver license expires')
                    ->filterable()
                    ->sortable(),

                Column::make('status', 'string')
                    ->label('Status')
                    ->description('Driver employment status')
                    ->filterable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        $labels = [
                            'active'     => 'Active',
                            'inactive'   => 'Inactive',
                            'suspended'  => 'Suspended',
                            'terminated' => 'Terminated',
                        ];

                        return $labels[$value] ?? ucfirst($value);
                    }),

                Column::make('online', 'boolean')
                    ->label('Online')
                    ->description('Whether driver is currently online')
                    ->filterable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        return $value ? 'Yes' : 'No';
                    }),

                Column::make('city', 'string')
                    ->label('City')
                    ->filterable()
                    ->aggregatable(),

                Column::make('country', 'string')
                    ->label('Country')
                    ->filterable()
                    ->aggregatable(),

                Column::make('created_at', 'datetime')
                    ->label('Hired Date')
                    ->description('When the driver was added')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ])
            ->computedColumns([
                Column::count('total_drivers', 'DISTINCT id')
                    ->label('Total Drivers')
                    ->description('Count of drivers'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('user', 'users')
                    ->label('Driver')
                    ->localKey('user_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Name'),
                        Column::make('email', 'string')->label('Email'),
                        Column::make('phone', 'string')->label('Phone'),
                    ]),

                Relationship::hasAutoJoin('vehicle', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('vehicle_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Vehicle ID'),
                        Column::make('make', 'string')->label('Vehicle Make'),
                        Column::make('model', 'string')->label('Vehicle Model'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                    ]),
            ]);
    }

    /**
     * Create the Vehicles table definition.
     */
    protected function createVehiclesTable(): Table
    {
        return $this->softDeletes(Table::make('vehicles'))
            ->label('Vehicles')
            ->description('Fleet vehicles and assets')
            ->category('Fleet')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta'])
            ->maxRows(10000)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Vehicle ID')
                    ->description('Public vehicle identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('internal_id', 'string')
                    ->label('Internal ID')
                    ->description('Internal vehicle reference')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('name', 'string')
                    ->label('Name')
                    ->description('Vehicle name')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('make', 'string')
                    ->label('Make')
                    ->description('Vehicle manufacturer')
                    ->searchable()
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('model', 'string')
                    ->label('Model')
                    ->description('Vehicle model')
                    ->searchable()
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('year', 'integer')
                    ->label('Year')
                    ->description('Vehicle manufacturing year')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('plate_number', 'string')
                    ->label('Plate Number')
                    ->description('Vehicle license plate number')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('vin', 'string')
                    ->label('VIN')
                    ->description('Vehicle identification number')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('status', 'string')
                    ->label('Status')
                    ->description('Vehicle operational status')
                    ->filterable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        $labels = [
                            'active'         => 'Active',
                            'maintenance'    => 'In Maintenance',
                            'out_of_service' => 'Out of Service',
                            'retired'        => 'Retired',
                        ];

                        return $labels[$value] ?? ucfirst($value);
                    }),

                Column::make('created_at', 'datetime')
                    ->label('Added Date')
                    ->description('When the vehicle was added to fleet')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ])
            ->computedColumns([
                Column::count('total_vehicles', 'DISTINCT id')
                    ->label('Total Vehicles')
                    ->description('Count of vehicles'),
            ])
            ->relationships([
                // A driver points at the vehicle they drive (drivers.vehicle_uuid).
                $this->softDeletes(Relationship::hasAutoJoin('driver', 'drivers'))
                    ->label('Driver')
                    ->localKey('uuid')
                    ->foreignKey('vehicle_uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Driver ID'),
                        Column::make('status', 'string')->label('Driver Status'),
                    ])
                    ->with([
                        Relationship::hasAutoJoin('user', 'users')
                            ->label('Driver')
                            ->localKey('user_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('name', 'string')->label('Name'),
                                Column::make('email', 'string')->label('Email'),
                                Column::make('phone', 'string')->label('Phone'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Create the independently managed assets definition, including Trailers.
     */
    protected function createAssetsTable(): Table
    {
        return $this->softDeletes(Table::make('assets'))
            ->label('Trailers and Assets')
            ->description('Independently managed fleet assets; Trailer rows use asset_class trailer')
            ->category('Fleet')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'company_uuid', 'deleted_at', 'meta', 'attributes'])
            ->maxRows(10000)
            ->columns([
                Column::make('public_id', 'string')->label('Asset ID')->searchable()->filterable()->sortable(),
                Column::make('asset_class', 'string')->label('Asset Class')->filterable()->aggregatable(),
                Column::make('name', 'string')->label('Name')->searchable()->filterable()->sortable(),
                Column::make('code', 'string')->label('Code')->searchable()->filterable()->sortable(),
                Column::make('type', 'string')->label('Trailer Type')->filterable()->aggregatable(),
                Column::make('status', 'string')->label('Lifecycle Status')->filterable()->aggregatable(),
                Column::make('plate_number', 'string')->label('Plate Number')->searchable()->filterable(),
                Column::make('make', 'string')->label('Make')->filterable()->aggregatable(),
                Column::make('model', 'string')->label('Model')->filterable()->aggregatable(),
                Column::make('year', 'integer')->label('Year')->filterable()->sortable(),
                Column::make('gvwr', 'decimal')->label('GVWR')->filterable()->sortable()->aggregatable(),
                Column::make('payload_capacity', 'decimal')->label('Payload Capacity')->filterable()->sortable()->aggregatable(),
                Column::make('axle_count', 'integer')->label('Axle Count')->filterable()->sortable(),
                Column::make('online', 'boolean')->label('Online')->filterable()->aggregatable(),
                Column::make('last_online_at', 'datetime')->label('Last Online')->filterable()->sortable(),
                Column::make('created_at', 'datetime')->label('Created')->filterable()->sortable(),
            ])
            ->computedColumns([
                Column::count('total_assets', 'id')->label('Total Assets')->description('Count of selected trailer or asset rows'),
            ]);
    }

    /**
     * Create the Places table definition.
     */
    protected function createPlacesTable(): Table
    {
        return $this->softDeletes(Table::make('places'))
            ->label('Places')
            ->description('Locations and addresses')
            ->category('Geography')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta'])
            ->maxRows(100000)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Place ID')
                    ->description('Public place identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('name', 'string')
                    ->label('Name')
                    ->description('Place name or description')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('street1', 'string')
                    ->label('Street Address')
                    ->description('Primary street address')
                    ->searchable()
                    ->filterable(),

                Column::make('city', 'string')
                    ->label('City')
                    ->description('City name')
                    ->searchable()
                    ->filterable()
                    ->aggregatable(),

                Column::make('province', 'string')
                    ->label('Province/State')
                    ->description('Province or state')
                    ->filterable()
                    ->aggregatable(),

                Column::make('postal_code', 'string')
                    ->label('Postal Code')
                    ->description('Postal or ZIP code')
                    ->filterable(),

                Column::make('country', 'string')
                    ->label('Country')
                    ->description('Country name')
                    ->filterable()
                    ->aggregatable(),

                Column::make('created_at', 'datetime')
                    ->label('Created At')
                    ->description('When the place was created')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ]);
    }

    /**
     * Create the Contacts table definition.
     */
    protected function createContactsTable(): Table
    {
        return $this->softDeletes(Table::make('contacts'))
            ->label('Contacts')
            ->description('Customer and vendor contacts')
            ->category('CRM')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta'])
            ->maxRows(50000)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Contact ID')
                    ->description('Public contact identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('name', 'string')
                    ->label('Name')
                    ->description('Contact full name')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('email', 'string')
                    ->label('Email')
                    ->description('Contact email address')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('phone', 'string')
                    ->label('Phone')
                    ->description('Contact phone number')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('type', 'string')
                    ->label('Type')
                    ->description('Contact type')
                    ->filterable()
                    ->aggregatable(),

                Column::make('created_at', 'datetime')
                    ->label('Created At')
                    ->description('When the contact was created')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ]);
    }

    /**
     * Create the Vendors table definition.
     */
    protected function createVendorsTable(): Table
    {
        return $this->softDeletes(Table::make('vendors'))
            ->label('Vendors')
            ->description('Service providers and vendors')
            ->category('CRM')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta'])
            ->maxRows(10000)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Vendor ID')
                    ->description('Public vendor identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('name', 'string')
                    ->label('Name')
                    ->description('Vendor name')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('email', 'string')
                    ->label('Email')
                    ->description('Vendor email address')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('phone', 'string')
                    ->label('Phone')
                    ->description('Vendor phone number')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('type', 'string')
                    ->label('Type')
                    ->description('Vendor type')
                    ->filterable()
                    ->aggregatable(),

                Column::make('created_at', 'datetime')
                    ->label('Created At')
                    ->description('When the vendor was created')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ]);
    }

    /**
     * Create the Fuel Reports table definition.
     */
    protected function createFuelReportsTable(): Table
    {
        return $this->softDeletes(Table::make('fuel_reports'))
            ->label('Fuel Reports')
            ->description('Vehicle fuel consumption reports')
            ->category('Operations')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta'])
            ->maxRows(100000)
            ->columns([
                Column::make('public_id', 'string')
                    ->label('Report ID')
                    ->description('Public fuel report identifier')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('volume', 'decimal')
                    ->label('Volume')
                    ->description('Fuel volume, in the report\'s metric unit')
                    ->aggregatable()
                    ->sortable(),

                Column::make('metric_unit', 'string')
                    ->label('Volume Unit')
                    ->description('Unit of the fuel volume')
                    ->filterable()
                    ->aggregatable(),

                Column::make('odometer', 'integer')
                    ->label('Odometer Reading')
                    ->description('Vehicle odometer reading')
                    ->sortable(),

                Column::make('amount', 'decimal')
                    ->label('Cost')
                    ->description('Fuel cost amount')
                    ->aggregatable()
                    ->sortable(),

                Column::make('currency', 'string')
                    ->label('Currency')
                    ->description('Cost currency')
                    ->filterable()
                    ->aggregatable(),

                Column::make('status', 'string')
                    ->label('Status')
                    ->filterable()
                    ->aggregatable(),

                Column::make('report', 'string')
                    ->label('Report')
                    ->description('Report notes')
                    ->searchable()
                    ->filterable(),

                Column::make('created_at', 'datetime')
                    ->label('Report Date')
                    ->description('When the fuel report was recorded')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ])
            ->computedColumns([
                Column::sum('total_fuel_cost', 'amount')
                    ->label('Total Fuel Cost')
                    ->description('Sum of all fuel costs'),

                Column::sum('total_fuel_volume', 'volume')
                    ->label('Total Fuel Volume')
                    ->description('Sum of all fuel volumes'),

                Column::avg('average_fuel_cost', 'amount')
                    ->label('Average Fuel Cost')
                    ->description('Average fuel cost per report'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('vehicle', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('vehicle_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Vehicle ID'),
                        Column::make('make', 'string')->label('Vehicle Make'),
                        Column::make('model', 'string')->label('Vehicle Model'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                    ]),

                Relationship::hasAutoJoin('driver', 'drivers')
                    ->label('Driver')
                    ->localKey('driver_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Driver ID'),
                    ])
                    ->with([
                        Relationship::hasAutoJoin('user', 'users')
                            ->label('Driver')
                            ->localKey('user_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('name', 'string')->label('Name'),
                                Column::make('email', 'string')->label('Email'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Create the Work Orders table definition.
     */
    protected function createWorkOrdersTable(): Table
    {
        return $this->softDeletes(Table::make('work_orders'))
            ->label('Work Orders')
            ->description('Maintenance work orders, assignments, budgets, and lifecycle status')
            ->category('Maintenance')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta', 'checklist', 'cost_breakdown'])
            ->maxRows(100000)
            ->columns([
                Column::make('public_id', 'string')->label('Work Order ID')->searchable()->filterable()->sortable(),
                Column::make('code', 'string')->label('Code')->searchable()->filterable()->sortable(),
                Column::make('subject', 'string')->label('Subject')->searchable()->filterable()->sortable(),
                Column::make('status', 'string')->label('Status')->filterable()->sortable()->aggregatable(),
                Column::make('priority', 'string')->label('Priority')->filterable()->sortable()->aggregatable(),
                Column::make('opened_at', 'datetime')->label('Opened At')->filterable()->sortable()->aggregatable(),
                Column::make('due_at', 'datetime')->label('Due At')->filterable()->sortable()->aggregatable(),
                Column::make('closed_at', 'datetime')->label('Closed At')->filterable()->sortable()->aggregatable(),
                Column::make('estimated_cost', 'integer')->label('Estimated Cost')->aggregatable()->sortable(),
                Column::make('approved_budget', 'integer')->label('Approved Budget')->aggregatable()->sortable(),
                Column::make('actual_cost', 'integer')->label('Actual Cost')->aggregatable()->sortable(),
                Column::make('currency', 'string')->label('Currency')->filterable()->aggregatable(),
                Column::make('cost_center', 'string')->label('Cost Center')->filterable()->sortable(),
                Column::make('budget_code', 'string')->label('Budget Code')->filterable()->sortable(),
                Column::make('created_at', 'datetime')->label('Created At')->filterable()->sortable()->aggregatable(),
            ])
            ->computedColumns([
                Column::count('total_work_orders', 'id')->label('Total Work Orders')->description('Count of work orders'),
                Column::sum('total_actual_cost', 'actual_cost')->label('Total Actual Cost')->description('Sum of completed work order actual cost'),
                Column::avg('average_actual_cost', 'actual_cost')->label('Average Actual Cost')->description('Average actual work order cost'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('vehicle_target', 'vehicles')
                    ->label('Target Vehicle')
                    ->localKey('target_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Vehicle ID'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                        Column::make('make', 'string')->label('Make'),
                        Column::make('model', 'string')->label('Model'),
                        Column::make('odometer', 'integer')->label('Odometer'),
                    ]),
            ]);
    }

    /**
     * Create the Maintenances table definition.
     */
    protected function createMaintenancesTable(): Table
    {
        return $this->softDeletes(Table::make('maintenances'))
            ->label('Maintenance History')
            ->description('Completed and scheduled maintenance records with labor, parts, tax, and total cost')
            ->category('Maintenance')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta', 'line_items', 'attachments'])
            ->maxRows(100000)
            ->columns([
                Column::make('public_id', 'string')->label('Maintenance ID')->searchable()->filterable()->sortable(),
                Column::make('type', 'string')->label('Type')->filterable()->sortable()->aggregatable(),
                Column::make('status', 'string')->label('Status')->filterable()->sortable()->aggregatable(),
                Column::make('priority', 'string')->label('Priority')->filterable()->sortable()->aggregatable(),
                Column::make('scheduled_at', 'datetime')->label('Scheduled At')->filterable()->sortable()->aggregatable(),
                Column::make('started_at', 'datetime')->label('Started At')->filterable()->sortable()->aggregatable(),
                Column::make('completed_at', 'datetime')->label('Completed At')->filterable()->sortable()->aggregatable(),
                Column::make('odometer', 'integer')->label('Odometer')->aggregatable()->sortable(),
                Column::make('engine_hours', 'integer')->label('Engine Hours')->aggregatable()->sortable(),
                Column::make('summary', 'string')->label('Summary')->searchable()->filterable()->sortable(),
                Column::make('labor_cost', 'integer')->label('Labor Cost')->aggregatable()->sortable(),
                Column::make('parts_cost', 'integer')->label('Parts Cost')->aggregatable()->sortable(),
                Column::make('tax', 'integer')->label('Tax')->aggregatable()->sortable(),
                Column::make('total_cost', 'integer')->label('Total Cost')->aggregatable()->sortable(),
                Column::make('currency', 'string')->label('Currency')->filterable()->aggregatable(),
                Column::make('created_at', 'datetime')->label('Created At')->filterable()->sortable()->aggregatable(),
            ])
            ->computedColumns([
                Column::count('total_maintenance_records', 'id')->label('Total Maintenance Records')->description('Count of maintenance records'),
                Column::sum('total_maintenance_cost', 'total_cost')->label('Total Maintenance Cost')->description('Sum of maintenance total cost'),
                Column::avg('average_maintenance_cost', 'total_cost')->label('Average Maintenance Cost')->description('Average maintenance total cost'),
                Column::sum('total_parts_cost', 'parts_cost')->label('Total Parts Cost')->description('Sum of parts cost'),
                Column::sum('total_labor_cost', 'labor_cost')->label('Total Labor Cost')->description('Sum of labor cost'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('work_order', 'work_orders')
                    ->label('Work Order')
                    ->localKey('work_order_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Work Order ID'),
                        Column::make('code', 'string')->label('Work Order Code'),
                        Column::make('subject', 'string')->label('Work Order Subject'),
                    ]),
                Relationship::hasAutoJoin('vehicle', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('maintainable_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Vehicle ID'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                        Column::make('make', 'string')->label('Make'),
                        Column::make('model', 'string')->label('Model'),
                        Column::make('acquisition_cost', 'integer')->label('Acquisition Cost'),
                    ]),
            ]);
    }

    /**
     * Create the Inspection Submissions table definition.
     */
    protected function createInspectionSubmissionsTable(): Table
    {
        return $this->softDeletes(Table::make('inspection_submissions'))
            ->label('Inspections')
            ->description('DVIR and inspection submissions, pass/fail status, and linked maintenance follow-up')
            ->category('Maintenance')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta', 'location', 'signature', 'attachments'])
            ->maxRows(100000)
            ->columns([
                Column::make('public_id', 'string')->label('Inspection ID')->searchable()->filterable()->sortable(),
                Column::make('type', 'string')->label('Type')->filterable()->sortable()->aggregatable(),
                Column::make('status', 'string')->label('Status')->filterable()->sortable()->aggregatable(),
                Column::make('result', 'string')->label('Result')->filterable()->sortable()->aggregatable(),
                Column::make('source', 'string')->label('Source')->filterable()->sortable()->aggregatable(),
                Column::make('odometer', 'integer')->label('Odometer')->aggregatable()->sortable(),
                Column::make('engine_hours', 'integer')->label('Engine Hours')->aggregatable()->sortable(),
                Column::make('total_items', 'integer')->label('Total Items')->aggregatable()->sortable(),
                Column::make('failed_items', 'integer')->label('Failed Items')->aggregatable()->sortable(),
                Column::make('started_at', 'datetime')->label('Started At')->filterable()->sortable()->aggregatable(),
                Column::make('submitted_at', 'datetime')->label('Submitted At')->filterable()->sortable()->aggregatable(),
                Column::make('resolved_at', 'datetime')->label('Resolved At')->filterable()->sortable()->aggregatable(),
                Column::make('created_at', 'datetime')->label('Created At')->filterable()->sortable()->aggregatable(),
            ])
            ->computedColumns([
                Column::count('total_inspections', 'id')->label('Total Inspections')->description('Count of inspection submissions'),
                Column::sum('total_failed_items', 'failed_items')->label('Total Failed Items')->description('Sum of failed inspection items'),
                Column::avg('average_failed_items', 'failed_items')->label('Average Failed Items')->description('Average failed items per inspection'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('inspection_form', 'inspection_forms')
                    ->label('Inspection Form')
                    ->localKey('inspection_form_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Form Name'),
                        Column::make('type', 'string')->label('Form Type'),
                    ]),
                Relationship::hasAutoJoin('vehicle', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('vehicle_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Vehicle ID'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                        Column::make('make', 'string')->label('Make'),
                        Column::make('model', 'string')->label('Model'),
                    ]),
                Relationship::hasAutoJoin('work_order', 'work_orders')
                    ->label('Work Order')
                    ->localKey('work_order_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('public_id', 'string')->label('Work Order ID'),
                        Column::make('status', 'string')->label('Work Order Status'),
                        Column::make('actual_cost', 'integer')->label('Work Order Actual Cost'),
                    ]),
            ]);
    }

    /**
     * Columns reported for a place (pickup, dropoff, return or item destination).
     */
    protected function placeColumns(): array
    {
        return [
            Column::make('public_id', 'string')->label('ID'),
            Column::make('name', 'string')->label('Name'),
            Column::make('street1', 'string')->label('Street'),
            Column::make('street2', 'string')->label('Street 2'),
            Column::make('neighborhood', 'string')->label('Neighborhood'),
            Column::make('district', 'string')->label('District'),
            Column::make('city', 'string')->label('City'),
            Column::make('province', 'string')->label('Province'),
            Column::make('postal_code', 'string')->label('Postal Code'),
            Column::make('country', 'string')->label('Country'),
            Column::make('latitude', 'decimal')->label('Latitude'),
            Column::make('longitude', 'decimal')->label('Longitude'),
        ];
    }

    /**
     * SQL reading a numeric amount stored under a key of a JSON column.
     */
    protected function jsonAmount(string $column, string $key): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}')) AS DECIMAL(15,2))";
    }

    /**
     * A row-level expression column, such as a value read out of a JSON column.
     *
     * Core API releases before expression columns only know computed columns; there the
     * column is still declared, it just cannot be selected on its own.
     */
    protected function expression(string $name, string $sql, string $type): Column
    {
        // One line: whichever branch runs depends on the installed Core API, not on the test.
        return method_exists(Column::class, 'expression') ? Column::expression($name, $sql, $type) : Column::computed($name, $sql, $type);
    }

    /**
     * Leave soft-deleted rows out of a table or joined relationship, on Core API releases that support it.
     *
     * @template T of Table|Relationship
     *
     * @param T $schema
     *
     * @return T
     */
    protected function softDeletes(Table|Relationship $schema): Table|Relationship
    {
        return method_exists($schema, 'softDeletes') ? $schema->softDeletes() : $schema;
    }
}
