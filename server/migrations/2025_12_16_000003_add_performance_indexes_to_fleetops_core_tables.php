<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Performance-optimized composite indexes for core FleetOps tables.
     * These indexes are specifically designed to optimize the most common and heaviest query patterns:
     *
     * 1. orders?unassigned=1
     *    - Filters: company_uuid, driver_assigned_uuid IS NULL, status NOT IN (...)
     *    - Needs: (company_uuid, driver_assigned_uuid, status)
     *
     * 2. orders?active=1&with_tracker_data=1
     *    - Filters: company_uuid, driver_assigned_uuid IS NOT NULL, status NOT IN (...)
     *    - Needs: (company_uuid, driver_assigned_uuid, status)
     *    - Also needs efficient joins with tracking_numbers, payloads, places, waypoints
     *
     * 3. General order queries with sorting by created_at
     *    - Needs: (company_uuid, created_at)
     *
     * 4. Relationship lookups (payload -> waypoints, places, entities)
     *    - Needs: Foreign key indexes on relationship columns
     */
    public function up(): void
    {
        // ========================================
        // ORDERS TABLE - Critical for performance
        // ========================================
        Schema::table('orders', function (Blueprint $table) {
            // CRITICAL: Optimize unassigned and active filters
            // This index supports both unassigned=1 and active=1 queries
            if (!$this->indexExists('orders', 'orders_company_driver_status_idx')) {
                $table->index(['company_uuid', 'driver_assigned_uuid', 'status'], 'orders_company_driver_status_idx');
            }

            // Optimize general queries with sorting
            if (!$this->indexExists('orders', 'orders_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'orders_company_created_idx');
            }

            // Optimize scheduled_at queries (for scheduled orders)
            if (!$this->indexExists('orders', 'orders_company_scheduled_idx')) {
                $table->index(['company_uuid', 'scheduled_at'], 'orders_company_scheduled_idx');
            }

            // Optimize dispatched queries
            if (!$this->indexExists('orders', 'orders_company_dispatched_idx')) {
                $table->index(['company_uuid', 'dispatched', 'dispatched_at'], 'orders_company_dispatched_idx');
            }

            // Optimize payload lookups (for eager loading)
            if (!$this->indexExists('orders', 'orders_payload_uuid_idx')) {
                $table->index('payload_uuid', 'orders_payload_uuid_idx');
            }

            // Optimize tracking number lookups
            if (!$this->indexExists('orders', 'orders_tracking_number_uuid_idx')) {
                $table->index('tracking_number_uuid', 'orders_tracking_number_uuid_idx');
            }

            // Optimize driver/vehicle assignment lookups
            if (!$this->indexExists('orders', 'orders_vehicle_assigned_uuid_idx')) {
                $table->index('vehicle_assigned_uuid', 'orders_vehicle_assigned_uuid_idx');
            }
        });

        // ========================================
        // PAYLOADS TABLE
        // ========================================
        Schema::table('payloads', function (Blueprint $table) {
            // Optimize company queries
            if (!$this->indexExists('payloads', 'payloads_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'payloads_company_created_idx');
            }

            // Optimize pickup/dropoff/return lookups (for order queries)
            if (!$this->indexExists('payloads', 'payloads_pickup_uuid_idx')) {
                $table->index('pickup_uuid', 'payloads_pickup_uuid_idx');
            }
            if (!$this->indexExists('payloads', 'payloads_dropoff_uuid_idx')) {
                $table->index('dropoff_uuid', 'payloads_dropoff_uuid_idx');
            }
            if (!$this->indexExists('payloads', 'payloads_return_uuid_idx')) {
                $table->index('return_uuid', 'payloads_return_uuid_idx');
            }
        });

        // ========================================
        // WAYPOINTS TABLE
        // ========================================
        Schema::table('waypoints', function (Blueprint $table) {
            // Optimize payload -> waypoints relationship queries
            if (!$this->indexExists('waypoints', 'waypoints_payload_created_idx')) {
                $table->index(['payload_uuid', 'created_at'], 'waypoints_payload_created_idx');
            }

            // Optimize company queries
            if (!$this->indexExists('waypoints', 'waypoints_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'waypoints_company_created_idx');
            }

            // Optimize place lookups
            if (!$this->indexExists('waypoints', 'waypoints_place_uuid_idx')) {
                $table->index('place_uuid', 'waypoints_place_uuid_idx');
            }
        });

        // ========================================
        // ENTITIES TABLE
        // ========================================
        Schema::table('entities', function (Blueprint $table) {
            // Optimize payload -> entities relationship queries
            if (!$this->indexExists('entities', 'entities_payload_created_idx')) {
                $table->index(['payload_uuid', 'created_at'], 'entities_payload_created_idx');
            }

            // Optimize company queries
            if (!$this->indexExists('entities', 'entities_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'entities_company_created_idx');
            }

            // Optimize destination lookups
            if (!$this->indexExists('entities', 'entities_destination_uuid_idx')) {
                $table->index('destination_uuid', 'entities_destination_uuid_idx');
            }
        });

        // ========================================
        // PLACES TABLE
        // ========================================
        Schema::table('places', function (Blueprint $table) {
            // Optimize company queries
            if (!$this->indexExists('places', 'places_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'places_company_created_idx');
            }

            // Optimize owner lookups (polymorphic)
            if (!$this->indexExists('places', 'places_owner_idx')) {
                $table->index(['owner_uuid', 'owner_type'], 'places_owner_idx');
            }
        });

        // ========================================
        // DRIVERS TABLE
        // ========================================
        Schema::table('drivers', function (Blueprint $table) {
            // Optimize company queries with status filter
            if (!$this->indexExists('drivers', 'drivers_company_status_online_idx')) {
                $table->index(['company_uuid', 'status', 'online'], 'drivers_company_status_online_idx');
            }

            // Optimize general queries
            if (!$this->indexExists('drivers', 'drivers_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'drivers_company_created_idx');
            }

            // Optimize user lookups
            if (!$this->indexExists('drivers', 'drivers_user_uuid_idx')) {
                $table->index('user_uuid', 'drivers_user_uuid_idx');
            }

            // Optimize vendor lookups
            if (!$this->indexExists('drivers', 'drivers_vendor_uuid_idx')) {
                $table->index('vendor_uuid', 'drivers_vendor_uuid_idx');
            }
        });

        // ========================================
        // VEHICLES TABLE
        // ========================================
        Schema::table('vehicles', function (Blueprint $table) {
            // Optimize company queries with status filter
            if (!$this->indexExists('vehicles', 'vehicles_company_status_online_idx')) {
                $table->index(['company_uuid', 'status', 'online'], 'vehicles_company_status_online_idx');
            }

            // Optimize general queries
            if (!$this->indexExists('vehicles', 'vehicles_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'vehicles_company_created_idx');
            }

            // Optimize vendor lookups
            if (!$this->indexExists('vehicles', 'vehicles_vendor_uuid_idx')) {
                $table->index('vendor_uuid', 'vehicles_vendor_uuid_idx');
            }
        });

        // ========================================
        // VENDORS TABLE
        // ========================================
        Schema::table('vendors', function (Blueprint $table) {
            // Optimize company queries with status filter
            if (!$this->indexExists('vendors', 'vendors_company_status_idx')) {
                $table->index(['company_uuid', 'status'], 'vendors_company_status_idx');
            }

            // Already has company_uuid and created_at indexes
        });

        // ========================================
        // CONTACTS TABLE
        // ========================================
        Schema::table('contacts', function (Blueprint $table) {
            // Optimize company queries
            if (!$this->indexExists('contacts', 'contacts_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'contacts_company_created_idx');
            }

            // Optimize company queries with type filter
            if (!$this->indexExists('contacts', 'contacts_company_type_idx')) {
                $table->index(['company_uuid', 'type'], 'contacts_company_type_idx');
            }
        });

        // ========================================
        // ROUTES TABLE
        // ========================================
        Schema::table('routes', function (Blueprint $table) {
            // Optimize company queries
            if (!$this->indexExists('routes', 'routes_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'routes_company_created_idx');
            }

            // Note: routes table doesn't have a status column in this schema
        });

        // ========================================
        // TRACKING_NUMBERS TABLE
        // ========================================
        Schema::table('tracking_numbers', function (Blueprint $table) {
            // Optimize company queries
            if (!$this->indexExists('tracking_numbers', 'tracking_numbers_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'tracking_numbers_company_created_idx');
            }

            // Optimize tracking_number lookups (for public tracking)
            if (!$this->indexExists('tracking_numbers', 'tracking_numbers_tracking_number_idx')) {
                $table->index('tracking_number', 'tracking_numbers_tracking_number_idx');
            }

            // Optimize owner lookups (polymorphic)
            if (!$this->indexExists('tracking_numbers', 'tracking_numbers_owner_idx')) {
                $table->index(['owner_uuid', 'owner_type'], 'tracking_numbers_owner_idx');
            }
        });

        // ========================================
        // TRACKING_STATUSES TABLE
        // ========================================
        Schema::table('tracking_statuses', function (Blueprint $table) {
            // Optimize tracking_number_uuid lookups (for order tracking history)
            if (!$this->indexExists('tracking_statuses', 'tracking_statuses_tracking_created_idx')) {
                $table->index(['tracking_number_uuid', 'created_at'], 'tracking_statuses_tracking_created_idx');
            }

            // Optimize company queries
            if (!$this->indexExists('tracking_statuses', 'tracking_statuses_company_created_idx')) {
                $table->index(['company_uuid', 'created_at'], 'tracking_statuses_company_created_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ORDERS
        Schema::table('orders', function (Blueprint $table) {
            if ($this->indexExists('orders', 'orders_company_driver_status_idx')) {
                $table->dropIndex('orders_company_driver_status_idx');
            }
            if ($this->indexExists('orders', 'orders_company_created_idx')) {
                $table->dropIndex('orders_company_created_idx');
            }
            if ($this->indexExists('orders', 'orders_company_scheduled_idx')) {
                $table->dropIndex('orders_company_scheduled_idx');
            }
            if ($this->indexExists('orders', 'orders_company_dispatched_idx')) {
                $table->dropIndex('orders_company_dispatched_idx');
            }
            if ($this->indexExists('orders', 'orders_payload_uuid_idx')) {
                $table->dropIndex('orders_payload_uuid_idx');
            }
            if ($this->indexExists('orders', 'orders_tracking_number_uuid_idx')) {
                $table->dropIndex('orders_tracking_number_uuid_idx');
            }
            if ($this->indexExists('orders', 'orders_vehicle_assigned_uuid_idx')) {
                $table->dropIndex('orders_vehicle_assigned_uuid_idx');
            }
        });

        // PAYLOADS
        Schema::table('payloads', function (Blueprint $table) {
            if ($this->indexExists('payloads', 'payloads_company_created_idx')) {
                $table->dropIndex('payloads_company_created_idx');
            }
            if ($this->indexExists('payloads', 'payloads_pickup_uuid_idx')) {
                $table->dropIndex('payloads_pickup_uuid_idx');
            }
            if ($this->indexExists('payloads', 'payloads_dropoff_uuid_idx')) {
                $table->dropIndex('payloads_dropoff_uuid_idx');
            }
            if ($this->indexExists('payloads', 'payloads_return_uuid_idx')) {
                $table->dropIndex('payloads_return_uuid_idx');
            }
        });

        // WAYPOINTS
        Schema::table('waypoints', function (Blueprint $table) {
            if ($this->indexExists('waypoints', 'waypoints_payload_created_idx')) {
                $table->dropIndex('waypoints_payload_created_idx');
            }
            if ($this->indexExists('waypoints', 'waypoints_company_created_idx')) {
                $table->dropIndex('waypoints_company_created_idx');
            }
            if ($this->indexExists('waypoints', 'waypoints_place_uuid_idx')) {
                $table->dropIndex('waypoints_place_uuid_idx');
            }
        });

        // ENTITIES
        Schema::table('entities', function (Blueprint $table) {
            if ($this->indexExists('entities', 'entities_payload_created_idx')) {
                $table->dropIndex('entities_payload_created_idx');
            }
            if ($this->indexExists('entities', 'entities_company_created_idx')) {
                $table->dropIndex('entities_company_created_idx');
            }
            if ($this->indexExists('entities', 'entities_destination_uuid_idx')) {
                $table->dropIndex('entities_destination_uuid_idx');
            }
        });

        // PLACES
        Schema::table('places', function (Blueprint $table) {
            if ($this->indexExists('places', 'places_company_created_idx')) {
                $table->dropIndex('places_company_created_idx');
            }
            if ($this->indexExists('places', 'places_owner_idx')) {
                $table->dropIndex('places_owner_idx');
            }
        });

        // DRIVERS
        Schema::table('drivers', function (Blueprint $table) {
            if ($this->indexExists('drivers', 'drivers_company_status_online_idx')) {
                $table->dropIndex('drivers_company_status_online_idx');
            }
            if ($this->indexExists('drivers', 'drivers_company_created_idx')) {
                $table->dropIndex('drivers_company_created_idx');
            }
            if ($this->indexExists('drivers', 'drivers_user_uuid_idx')) {
                $table->dropIndex('drivers_user_uuid_idx');
            }
            if ($this->indexExists('drivers', 'drivers_vendor_uuid_idx')) {
                $table->dropIndex('drivers_vendor_uuid_idx');
            }
        });

        // VEHICLES
        Schema::table('vehicles', function (Blueprint $table) {
            if ($this->indexExists('vehicles', 'vehicles_company_status_online_idx')) {
                $table->dropIndex('vehicles_company_status_online_idx');
            }
            if ($this->indexExists('vehicles', 'vehicles_company_created_idx')) {
                $table->dropIndex('vehicles_company_created_idx');
            }
            if ($this->indexExists('vehicles', 'vehicles_vendor_uuid_idx')) {
                $table->dropIndex('vehicles_vendor_uuid_idx');
            }
        });

        // VENDORS
        Schema::table('vendors', function (Blueprint $table) {
            if ($this->indexExists('vendors', 'vendors_company_status_idx')) {
                $table->dropIndex('vendors_company_status_idx');
            }
        });

        // CONTACTS
        Schema::table('contacts', function (Blueprint $table) {
            if ($this->indexExists('contacts', 'contacts_company_created_idx')) {
                $table->dropIndex('contacts_company_created_idx');
            }
            if ($this->indexExists('contacts', 'contacts_company_type_idx')) {
                $table->dropIndex('contacts_company_type_idx');
            }
        });

        // ROUTES
        Schema::table('routes', function (Blueprint $table) {
            if ($this->indexExists('routes', 'routes_company_created_idx')) {
                $table->dropIndex('routes_company_created_idx');
            }
        });

        // TRACKING_NUMBERS
        Schema::table('tracking_numbers', function (Blueprint $table) {
            if ($this->indexExists('tracking_numbers', 'tracking_numbers_company_created_idx')) {
                $table->dropIndex('tracking_numbers_company_created_idx');
            }
            if ($this->indexExists('tracking_numbers', 'tracking_numbers_tracking_number_idx')) {
                $table->dropIndex('tracking_numbers_tracking_number_idx');
            }
            if ($this->indexExists('tracking_numbers', 'tracking_numbers_owner_idx')) {
                $table->dropIndex('tracking_numbers_owner_idx');
            }
        });

        // TRACKING_STATUSES
        Schema::table('tracking_statuses', function (Blueprint $table) {
            if ($this->indexExists('tracking_statuses', 'tracking_statuses_tracking_created_idx')) {
                $table->dropIndex('tracking_statuses_tracking_created_idx');
            }
            if ($this->indexExists('tracking_statuses', 'tracking_statuses_company_created_idx')) {
                $table->dropIndex('tracking_statuses_company_created_idx');
            }
        });
    }

    /**
     * Check if an index exists on a table.
     */
    protected function indexExists(string $table, string $index): bool
    {
        try {
            $connection            = Schema::getConnection();
            $doctrineSchemaManager = $connection->getDoctrineSchemaManager();
            $indexes               = $doctrineSchemaManager->listTableIndexes($table);

            return isset($indexes[$index]);
        } catch (Exception $e) {
            return false;
        }
    }
};
