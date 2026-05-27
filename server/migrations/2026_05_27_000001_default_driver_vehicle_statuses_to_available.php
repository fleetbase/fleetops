<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('drivers')->where(function ($query) {
            $query->where('status', 'active')->orWhereNull('status');
        })->update(['status' => 'available']);

        DB::table('vehicles')->where(function ($query) {
            $query->where('status', 'active')->orWhereNull('status');
        })->update(['status' => 'available']);

        Schema::table('drivers', function (Blueprint $table) {
            $table->string('status', 191)->nullable()->default('available')->change();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('status')->nullable()->default('available')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('status', 191)->nullable()->default(null)->change();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('status')->nullable()->default(null)->change();
        });
    }
};
