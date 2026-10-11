<?php

namespace Fleetbase\Tests\Support;

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * An in-memory database holding the tables the public tracking page reads and writes,
 * so its queries run for real in tests.
 */
final class TrackingPageDatabase
{
    public static function boot(): SQLiteConnection
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'));
        $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'testing' => $connection]);
        $resolver->setDefaultConnection('mysql');
        EloquentModel::setConnectionResolver($resolver);
        EloquentModel::clearBootedModels();

        $schema = $connection->getSchemaBuilder();
        $common = function ($table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->text('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        };

        $schema->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $schema->create('tracking_numbers', function ($table) use ($common) {
            $common($table);
            $table->string('tracking_number')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('region')->nullable();
            $table->string('status_uuid')->nullable();
            $table->text('qr_code')->nullable();
            $table->text('barcode')->nullable();
        });
        $schema->create('orders', function ($table) use ($common) {
            $common($table);
            $table->string('payload_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('tracking_number_uuid')->nullable();
            $table->string('driver_assigned_uuid')->nullable();
            $table->string('vehicle_assigned_uuid')->nullable();
            $table->string('status')->nullable();
            $table->boolean('dispatched')->nullable();
            $table->boolean('started')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
        });
        $schema->create('payloads', function ($table) use ($common) {
            $common($table);
            $table->string('pickup_uuid')->nullable();
            $table->string('dropoff_uuid')->nullable();
            $table->string('pickup_tracking_number_uuid')->nullable();
            $table->string('dropoff_tracking_number_uuid')->nullable();
        });
        $schema->create('waypoints', function ($table) use ($common) {
            $common($table);
            $table->string('payload_uuid')->nullable();
            $table->string('place_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('tracking_number_uuid')->nullable();
            $table->integer('order')->nullable();
        });
        $schema->create('entities', function ($table) use ($common) {
            $common($table);
            $table->string('payload_uuid')->nullable();
            $table->string('destination_uuid')->nullable();
            $table->string('tracking_number_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('photo_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('price')->nullable();
            $table->string('currency')->nullable();
        });
        $schema->create('proofs', function ($table) use ($common) {
            $common($table);
            $table->string('order_uuid')->nullable();
            $table->string('subject_uuid')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('file_uuid')->nullable();
            $table->text('raw_data')->nullable();
            $table->string('remarks')->nullable();
            $table->text('data')->nullable();
        });
        $schema->create('companies', function ($table) use ($common) {
            $common($table);
            $table->string('name')->nullable();
            $table->string('logo_uuid')->nullable();
            $table->string('phone')->nullable();
            $table->string('website_url')->nullable();
            $table->text('options')->nullable();
        });
        $schema->create('places', function ($table) use ($common) {
            $common($table);
            $table->string('name')->nullable();
            $table->string('street1')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->text('location')->nullable();
        });
        $schema->create('files', function ($table) use ($common) {
            $common($table);
            $table->string('type')->nullable();
            $table->string('path')->nullable();
            $table->string('disk')->nullable();
        });
        $schema->create('contacts', function ($table) use ($common) {
            $common($table);
            $table->string('user_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->nullable();
        });
        $schema->create('vendors', function ($table) use ($common) {
            $common($table);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
        });
        $schema->create('tracking_statuses', function ($table) use ($common) {
            $common($table);
            $table->string('tracking_number_uuid')->nullable();
            $table->string('status')->nullable();
            $table->string('details')->nullable();
            $table->string('code')->nullable();
            $table->boolean('complete')->nullable();
        });
        $schema->create('verification_codes', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('subject_uuid')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('code')->nullable();
            $table->string('for')->nullable();
            $table->text('meta')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('tracking_sessions', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('token_hash')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $schema->create('tracking_session_grants', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('tracking_session_uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('order_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('channel')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        return $connection;
    }

    public static function insert(SQLiteConnection $connection, string $table, array $row): void
    {
        $connection->table($table)->insert($row);
    }
}
