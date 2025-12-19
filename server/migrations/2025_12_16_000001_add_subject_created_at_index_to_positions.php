<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Performance optimization: Add composite index on (subject_uuid, created_at)
     * to dramatically improve query performance for the positions endpoint.
     *
     * This index supports queries like:
     *   SELECT * FROM positions WHERE subject_uuid = ? ORDER BY created_at DESC
     *
     * Expected performance improvement:
     *   - positions endpoint: 5605ms → 100-200ms (96-98% improvement)
     *
     * @return void
     */
    public function up()
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->index(['subject_uuid', 'created_at'], 'positions_subject_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('positions_subject_created_at_index');
        });
    }
};
