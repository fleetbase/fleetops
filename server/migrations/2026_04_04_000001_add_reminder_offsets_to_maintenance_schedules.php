<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add reminder_offsets JSON column to maintenance_schedules
        if (Schema::hasTable('maintenance_schedules') && !Schema::hasColumn('maintenance_schedules', 'reminder_offsets')) {
            Schema::table('maintenance_schedules', function (Blueprint $table) {
                $table->json('reminder_offsets')->nullable()->after('instructions')
                    ->comment('Array of integers: days before next_due_date to send reminder emails, e.g. [15, 7, 3]');
            });
        }

        // Create the reminder tracking table
        if (!Schema::hasTable('maintenance_schedule_reminders')) {
            Schema::create('maintenance_schedule_reminders', function (Blueprint $table) {
                $table->id();
                $table->string('schedule_uuid', 191)->index();
                $table->unsignedTinyInteger('offset_days')
                    ->comment('Which offset fired, e.g. 15 or 3');
                $table->date('due_date_snapshot')
                    ->comment('The next_due_date at time of sending; advances each cycle so reminders re-fire');
                $table->timestamp('sent_at')->useCurrent();

                $table->unique(['schedule_uuid', 'offset_days', 'due_date_snapshot'], 'unique_schedule_reminder');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedule_reminders');

        if (Schema::hasTable('maintenance_schedules') && Schema::hasColumn('maintenance_schedules', 'reminder_offsets')) {
            Schema::table('maintenance_schedules', function (Blueprint $table) {
                $table->dropColumn('reminder_offsets');
            });
        }
    }
};
