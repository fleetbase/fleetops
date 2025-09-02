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
        Schema::create('warranties', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('vendor_uuid')->nullable()->constrained('vendors', 'uuid')->nullOnDelete();
            $table->foreignUuid('category_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            // Covered item
            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->index(['subject_type', 'subject_uuid']);

            $table->string('provider')->nullable()->index();    // manufacturer or third-party
            $table->string('policy_number')->nullable()->index();
            $table->string('type')->nullable()->index();

            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable()->index();

            $table->json('coverage')->nullable();   // { parts: true, labor: false, roadside: true, limits: ... }
            $table->json('terms')->nullable();      // text/structured
            $table->text('policy')->nullable();      // text/structured
            $table->json('meta')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('warranties');
        Schema::enableForeignKeyConstraints();
    }
};
