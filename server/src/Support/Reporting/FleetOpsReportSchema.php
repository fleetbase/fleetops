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

        // Register Places table
        $registry->registerTable($this->createPlacesTable());

        // Register Contacts table
        $registry->registerTable($this->createContactsTable());

        // Register Vendors table
        $registry->registerTable($this->createVendorsTable());

        // Register Fuel Reports table
        $registry->registerTable($this->createFuelReportsTable());
    }

    /**
     * Create the Orders table definition.
     */
    protected function createOrdersTable(): Table
    {
        return Table::make('orders')
            ->label('Orders')
            ->description('Delivery and service orders')
            ->category('Operations')
            ->extension('fleet-ops')
            ->excludeColumns(['uuid', 'deleted_at', 'meta']) // Hide foreign keys and system columns
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
                    ->description('Type of order service')
                    ->filterable()
                    ->aggregatable(),

                Column::make('scheduled_at', 'datetime')
                    ->label('Scheduled At')
                    ->description('When the order is scheduled for')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('dispatched_at', 'datetime')
                    ->label('Dispatched At')
                    ->description('When the order was dispatched')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('started_at', 'datetime')
                    ->label('Started At')
                    ->description('When the order was started')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),

                Column::make('distance', 'integer')
                    ->label('Distance (km)')
                    ->description('Total distance for the order')
                    ->aggregatable()
                    ->sortable()
                    ->transformer(function ($value) {
                        return round($value * 0.621371, 2); // Convert km to miles
                    }),

                Column::make('time', 'integer')
                    ->label('Duration (minutes)')
                    ->description('Estimated duration in minutes')
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

                Column::make('pod_required', 'boolean')
                    ->label('POD Required')
                    ->description('Whether proof of delivery is required')
                    ->filterable()
                    ->aggregatable()
                    ->transformer(function ($value) {
                        return $value ? 'Yes' : 'No';
                    }),

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
            ])
            ->computedColumns([
                Column::count('total_orders', 'id')
                    ->label('Total Orders')
                    ->description('Count of orders'),

                Column::sum('total_distance', 'distance')
                    ->label('Total Distance')
                    ->description('Sum of all order distances'),

                Column::avg('average_distance', 'distance')
                    ->label('Average Distance')
                    ->description('Average distance per order'),

                Column::sum('total_time', 'time')
                    ->label('Total Time')
                    ->description('Sum of all order durations'),

                Column::avg('average_time', 'time')
                    ->label('Average Time')
                    ->description('Average duration per order'),
            ])
            ->relationships([
                // Auto-join relationships for seamless access
                Relationship::hasAutoJoin('payload', 'payloads')
                    ->label('Payload')
                    ->localKey('payload_uuid')
                    ->foreignKey('uuid')
                    ->with([
                        Relationship::hasAutoJoin('pickup', 'places')
                            ->label('Pickup')
                            ->localKey('pickup_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('name', 'string')->label('Name'),
                                Column::make('street1', 'string')->label('Street'),
                                Column::make('street2', 'string')->label('Street 2'),
                                Column::make('city', 'string')->label('City'),
                                Column::make('province', 'string')->label('Province'),
                                Column::make('postal_code', 'string')->label('Postal Code'),
                                Column::make('country', 'string')->label('Country'),
                            ]),

                        Relationship::hasAutoJoin('dropoff', 'places')
                            ->label('Dropoff')
                            ->localKey('dropoff_uuid')
                            ->foreignKey('uuid')
                            ->columns([
                                Column::make('name', 'string')->label('Name'),
                                Column::make('street1', 'string')->label('Street'),
                                Column::make('street2', 'string')->label('Street 2'),
                                Column::make('city', 'string')->label('City'),
                                Column::make('province', 'string')->label('Province'),
                                Column::make('postal_code', 'string')->label('Postal Code'),
                                Column::make('country', 'string')->label('Country'),
                            ]),
                    ]),

                Relationship::hasAutoJoin('driver_assigned', 'drivers')
                    ->label('Driver')
                    ->localKey('driver_assigned_uuid')
                    ->foreignKey('uuid')
                    ->columns([
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
                    ->localKey('customer_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Name'),
                        Column::make('email', 'string')->label('Email'),
                        Column::make('phone', 'string')->label('Phone'),
                        Column::make('type', 'string')->label('Type'),
                    ]),

                Relationship::hasAutoJoin('facilitator', 'vendors')
                    ->label('Faciliator')
                    ->localKey('facilitator_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Name'),
                        Column::make('email', 'string')->label('Email'),
                        Column::make('phone', 'string')->label('Phone'),
                        Column::make('type', 'string')->label('Type'),
                    ]),
            ]);
    }

    /**
     * Create the Drivers table definition.
     */
    protected function createDriversTable(): Table
    {
        return Table::make('drivers')
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

                Column::make('name', 'string')
                    ->label('Name')
                    ->description('Driver full name')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('email', 'string')
                    ->label('Email')
                    ->description('Driver email address')
                    ->searchable()
                    ->filterable()
                    ->sortable(),

                Column::make('phone', 'string')
                    ->label('Phone')
                    ->description('Driver phone number')
                    ->searchable()
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

                Column::make('created_at', 'datetime')
                    ->label('Hired Date')
                    ->description('When the driver was hired')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ])
            ->computedColumns([
                Column::count('total_drivers', 'id')
                    ->label('Total Drivers')
                    ->description('Count of drivers'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('current_vehicle', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('current_vehicle_uuid')
                    ->foreignKey('uuid')
                    ->columns([
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
        return Table::make('vehicles')
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
                Column::count('total_vehicles', 'id')
                    ->label('Total Vehicles')
                    ->description('Count of vehicles'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('current_driver', 'drivers')
                    ->label('Driver')
                    ->localKey('current_driver_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Driver Name'),
                        Column::make('email', 'string')->label('Driver Email'),
                        Column::make('phone', 'string')->label('Driver Phone'),
                    ]),
            ]);
    }

    /**
     * Create the Places table definition.
     */
    protected function createPlacesTable(): Table
    {
        return Table::make('places')
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
        return Table::make('contacts')
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
        return Table::make('vendors')
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
        return Table::make('fuel_reports')
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
                    ->label('Volume (L)')
                    ->description('Fuel volume in liters')
                    ->aggregatable()
                    ->sortable(),

                Column::make('odometer_reading', 'integer')
                    ->label('Odometer Reading')
                    ->description('Vehicle odometer reading')
                    ->sortable(),

                Column::make('cost', 'decimal')
                    ->label('Cost')
                    ->description('Fuel cost amount')
                    ->aggregatable()
                    ->sortable(),

                Column::make('currency', 'string')
                    ->label('Currency')
                    ->description('Cost currency')
                    ->filterable()
                    ->aggregatable(),

                Column::make('report_date', 'date')
                    ->label('Report Date')
                    ->description('Date of fuel report')
                    ->filterable()
                    ->sortable()
                    ->aggregatable(),
            ])
            ->computedColumns([
                Column::sum('total_fuel_cost', 'cost')
                    ->label('Total Fuel Cost')
                    ->description('Sum of all fuel costs'),

                Column::sum('total_fuel_volume', 'volume')
                    ->label('Total Fuel Volume')
                    ->description('Sum of all fuel volumes'),

                Column::avg('average_fuel_cost', 'cost')
                    ->label('Average Fuel Cost')
                    ->description('Average fuel cost per report'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('vehicle', 'vehicles')
                    ->label('Vehicle')
                    ->localKey('vehicle_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('make', 'string')->label('Vehicle Make'),
                        Column::make('model', 'string')->label('Vehicle Model'),
                        Column::make('plate_number', 'string')->label('Plate Number'),
                    ]),

                Relationship::hasAutoJoin('driver', 'drivers')
                    ->label('Driver')
                    ->localKey('driver_uuid')
                    ->foreignKey('uuid')
                    ->columns([
                        Column::make('name', 'string')->label('Driver Name'),
                        Column::make('email', 'string')->label('Driver Email'),
                    ]),
            ]);
    }
}
