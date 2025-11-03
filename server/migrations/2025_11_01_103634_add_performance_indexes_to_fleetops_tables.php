<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ============================================================
        // ORDERS TABLE INDEXES
        // ============================================================
        Schema::table('orders', function (Blueprint $table) {
            /**
             * Index 1: company_uuid + status.
             *
             * WHY: The most common query is "get all orders for company X with status Y"
             * USED BY: OrderFilter status filtering, dashboard queries
             * QUERY: WHERE company_uuid = ? AND status IN (?, ?, ?)
             *
             * This index allows MySQL to:
             * 1. Jump to all rows for the company (first column)
             * 2. Filter by status within that company (second column)
             *
             * Without this, MySQL would use company_uuid index, then scan all
             * matching rows to filter by status (slow for large datasets).
             */
            if (!$this->indexExists('orders', 'idx_orders_company_status')) {
                $table->index(['company_uuid', 'status'], 'idx_orders_company_status');
            }

            /**
             * Index 2: company_uuid + driver_assigned_uuid.
             *
             * WHY: Finding unassigned orders is a critical operation
             * USED BY: OrderFilter unassigned() method, driver assignment UI
             * QUERY: WHERE company_uuid = ? AND driver_assigned_uuid IS NULL
             *
             * This index is particularly efficient for IS NULL checks because
             * MySQL stores NULL values in indexes (unlike some databases).
             */
            if (!$this->indexExists('orders', 'idx_orders_company_driver')) {
                $table->index(['company_uuid', 'driver_assigned_uuid'], 'idx_orders_company_driver');
            }

            /**
             * Index 3: company_uuid + created_at.
             *
             * WHY: Date-based filtering and sorting is extremely common
             * USED BY: Date range filters, "recent orders" queries
             * QUERY: WHERE company_uuid = ? AND created_at >= ? AND created_at <= ?
             * ORDER BY: created_at DESC
             *
             * This index supports both filtering AND sorting efficiently.
             * The ORDER BY can use the index for sorting without a filesort operation.
             */
            if (!$this->indexExists('orders', 'idx_orders_company_created')) {
                $table->index(['company_uuid', 'created_at'], 'idx_orders_company_created');
            }

            /**
             * Index 4: company_uuid + scheduled_at.
             *
             * WHY: Scheduled orders need to be queried efficiently
             * USED BY: Scheduler, upcoming deliveries view
             * QUERY: WHERE company_uuid = ? AND scheduled_at >= ? AND scheduled_at <= ?
             */
            if (!$this->indexExists('orders', 'idx_orders_company_scheduled')) {
                $table->index(['company_uuid', 'scheduled_at'], 'idx_orders_company_scheduled');
            }

            /**
             * Index 5: company_uuid + dispatched + status.
             *
             * WHY: Finding dispatched orders with specific statuses
             * USED BY: Active orders view, driver assignment
             * QUERY: WHERE company_uuid = ? AND dispatched = 1 AND status IN (?, ?)
             *
             * This is a 3-column composite index. MySQL will use it for:
             * - company_uuid alone
             * - company_uuid + dispatched
             * - company_uuid + dispatched + status (full index)
             *
             * But NOT for dispatched alone or status alone.
             */
            if (!$this->indexExists('orders', 'idx_orders_company_dispatched_status')) {
                $table->index(['company_uuid', 'dispatched', 'status'], 'idx_orders_company_dispatched_status');
            }

            /**
             * Index 6: company_uuid + tracking_number_uuid.
             *
             * WHY: Common join key and filtering key for tracking data.
             * USED BY: OrderFilter, tracking joins
             * QUERY: WHERE company_uuid = ? AND tracking_number_uuid = ?
             */
            if (!$this->indexExists('orders', 'idx_orders_company_tracking')) {
                $table->index(['company_uuid', 'tracking_number_uuid'], 'idx_orders_company_tracking');
            }
        });

        // ============================================================
        // PAYLOADS TABLE INDEXES
        // ============================================================
        Schema::table('payloads', function (Blueprint $table) {
            /**
             * Index 6-8: Foreign key indexes.
             *
             * WHY: JOINs require indexes on both sides of the join condition
             * USED BY: All queries that JOIN orders to payloads to places
             *
             * When we JOIN payloads to places:
             * JOIN places ON places.uuid = payloads.pickup_uuid
             *
             * MySQL needs an index on payloads.pickup_uuid to efficiently
             * find matching rows. Without it, MySQL does a full table scan
             * of the payloads table for each place.
             */
            if (!$this->indexExists('payloads', 'pickup_uuid')) {
                $table->index('pickup_uuid', 'idx_payloads_pickup');
            }
            if (!$this->indexExists('payloads', 'dropoff_uuid')) {
                $table->index('dropoff_uuid', 'idx_payloads_dropoff');
            }
            if (!$this->indexExists('payloads', 'return_uuid')) {
                $table->index('return_uuid', 'idx_payloads_return');
            }
        });

        // ============================================================
        // WAYPOINTS TABLE INDEXES
        // ============================================================
        Schema::table('waypoints', function (Blueprint $table) {
            /**
             * Index 9: payload_uuid.
             *
             * WHY: Loading waypoints for a payload is a 1-to-many relationship
             * USED BY: Eager loading payload.waypoints
             * QUERY: WHERE payload_uuid = ?
             *
             * Without this index, loading waypoints for 378 payloads would
             * require 378 table scans of the waypoints table.
             */
            if (!$this->indexExists('waypoints', 'payload_uuid')) {
                $table->index('payload_uuid', 'idx_waypoints_payload');
            }

            /**
             * Index 10: Composite index for deleted waypoints.
             *
             * WHY: We frequently query non-deleted waypoints for a payload
             * QUERY: WHERE payload_uuid = ? AND deleted_at IS NULL
             *
             * This composite index is more efficient than two separate indexes
             * because it allows MySQL to filter on both conditions using one index.
             */
            if (!$this->indexExists('waypoints', 'idx_waypoints_payload_deleted')) {
                $table->index(['payload_uuid', 'deleted_at'], 'idx_waypoints_payload_deleted');
            }
        });

        // ============================================================
        // TRACKING_STATUSES TABLE INDEXES
        // ============================================================
        Schema::table('tracking_statuses', function (Blueprint $table) {
            /**
             * Index 11: tracking_number_uuid.
             *
             * WHY: Loading tracking statuses for an order
             * USED BY: Eager loading order.trackingStatuses
             * QUERY: WHERE tracking_number_uuid = ?
             */
            if (!$this->indexExists('tracking_statuses', 'tracking_number_uuid')) {
                $table->index('tracking_number_uuid', 'idx_tracking_statuses_tracking_number');
            }
        });

        // ============================================================
        // ENTITIES TABLE INDEXES
        // ============================================================

        Schema::table('entities', function (Blueprint $table) {
            /**
             * Index 12: payload_uuid.
             *
             * WHY: Loading entities for a payload
             * USED BY: Eager loading payload.entities
             * QUERY: WHERE payload_uuid = ?
             */
            if (!$this->indexExists('entities', 'payload_uuid')) {
                $table->index('payload_uuid', 'idx_entities_payload');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_company_status');
            $table->dropIndex('idx_orders_company_driver');
            $table->dropIndex('idx_orders_company_created');
            $table->dropIndex('idx_orders_company_scheduled');
            $table->dropIndex('idx_orders_company_dispatched_status');
            $table->dropIndex('idx_orders_company_tracking');
        });

        Schema::table('payloads', function (Blueprint $table) {
            $table->dropIndex('idx_payloads_pickup');
            $table->dropIndex('idx_payloads_dropoff');
            $table->dropIndex('idx_payloads_return');
        });

        Schema::table('waypoints', function (Blueprint $table) {
            $table->dropIndex('idx_waypoints_payload');
            $table->dropIndex('idx_waypoints_payload_deleted');
        });

        Schema::table('tracking_statuses', function (Blueprint $table) {
            $table->dropIndex('idx_tracking_statuses_tracking_number');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex('idx_entities_payload');
        });
    }

    /**
     * Check if an index exists on a table.
     *
     * This helper method prevents errors when running migrations multiple times
     * or when indexes already exist from previous manual additions.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }
};
