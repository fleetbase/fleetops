<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `photo_uuid` column to the `equipments` and `parts` tables.
 *
 * Both models have a `photo()` BelongsTo relationship to the `files` table and a
 * `getPhotoUrlAttribute()` accessor, but the backing foreign key column was omitted
 * from the original create migrations. This migration adds it as a nullable FK so
 * existing rows are unaffected.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('equipments') && !Schema::hasColumn('equipments', 'photo_uuid')) {
            Schema::table('equipments', function (Blueprint $table) {
                $table->foreignUuid('photo_uuid')
                      ->nullable()
                      ->after('warranty_uuid')
                      ->constrained('files', 'uuid')
                      ->nullOnDelete();
            });
        }

        if (Schema::hasTable('parts') && !Schema::hasColumn('parts', 'photo_uuid')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->foreignUuid('photo_uuid')
                      ->nullable()
                      ->after('warranty_uuid')
                      ->constrained('files', 'uuid')
                      ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('equipments') && Schema::hasColumn('equipments', 'photo_uuid')) {
            Schema::table('equipments', function (Blueprint $table) {
                $table->dropForeign(['photo_uuid']);
                $table->dropColumn('photo_uuid');
            });
        }

        if (Schema::hasTable('parts') && Schema::hasColumn('parts', 'photo_uuid')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->dropForeign(['photo_uuid']);
                $table->dropColumn('photo_uuid');
            });
        }
    }
};
