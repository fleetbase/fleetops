<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('location_type', 20)->nullable()->index()->after('type');
            $table->boolean('appointment_required')->default(false)->index()->after('location_type');
            $table->string('contact_name', 255)->nullable()->after('appointment_required');
            $table->string('contact_phone', 50)->nullable()->after('contact_name');
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropIndex(['location_type']);
            $table->dropIndex(['appointment_required']);
            $table->dropColumn([
                'location_type',
                'appointment_required',
                'contact_name',
                'contact_phone',
                'contact_email',
            ]);
        });
    }
};
