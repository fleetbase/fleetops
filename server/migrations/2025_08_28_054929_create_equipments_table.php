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
        Schema::create('equipments', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('category_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();

            $table->string('name')->index();
            $table->string('code')->nullable()->index();
            $table->string('type')->nullable()->index();     // ppe, tool, accessory, fridge unit, etc.
            $table->string('status')->default('active')->index();
            $table->string('slug')->nullable()->index();
            $table->string('serial_number')->nullable()->index();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();

            // Can be assigned to an asset, device, driver, or facility
            $table->string('equipable_type')->nullable();
            $table->uuid('equipable_uuid')->nullable();
            $table->index(['equipable_type', 'equipable_uuid']);

            $table->date('purchased_at')->nullable();
            $table->integer('purchase_price')->nullable();
            $table->string('currency')->nullable();

            $table->foreignUuid('warranty_uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();

            $table->json('meta')->nullable();
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('equipments');
        Schema::enableForeignKeyConstraints();
    }
};
