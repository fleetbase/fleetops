<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an inspection link be meant for anyone, and protect it with a PIN.
 *
 * A link could name a driver, and nobody else. Inspections are completed by
 * anyone in the organisation, so a link can now be assigned to any user;
 * the driver and vehicle stay as optional details of the inspection itself.
 *
 * A link now carries a PIN: a second thing to know alongside the link, which
 * can be sent to the assignee by email or SMS, or read out. It is kept hashed
 * for checking and encrypted so the console can show it again, and wrong
 * guesses are counted so the link locks after a few.
 */
return new class extends Migration {
    public function up()
    {
        Schema::table('inspection_links', function (Blueprint $table) {
            $table->foreignUuid('assignee_uuid')->nullable()->after('vehicle_uuid')->constrained('users', 'uuid')->nullOnDelete();
            $table->string('pin_hash')->nullable()->after('token');
            $table->text('pin')->nullable()->after('pin_hash');
            $table->unsignedSmallInteger('pin_attempts')->default(0)->after('pin');
            $table->string('pin_sent_via', 20)->nullable()->after('pin_attempts');
            $table->timestamp('pin_sent_at')->nullable()->after('pin_sent_via');
        });
    }

    public function down()
    {
        Schema::table('inspection_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignee_uuid');
            $table->dropColumn(['pin_hash', 'pin', 'pin_attempts', 'pin_sent_via', 'pin_sent_at']);
        });
    }
};
