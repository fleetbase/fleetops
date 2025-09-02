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
        Schema::create('parts', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('category_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();

            $table->string('sku')->nullable()->index();
            $table->string('name')->index();
            $table->string('manufacturer')->nullable()->index();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('type')->nullable()->index();
            $table->string('slug')->nullable()->index();

            $table->text('description')->nullable();
            $table->foreignUuid('vendor_uuid')->nullable()->constrained('vendors', 'uuid')->nullOnDelete();

            $table->integer('quantity_on_hand')->default(0);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('msrp', 12, 2)->nullable();
            $table->integer('reorder_point')->default(5);
            $table->integer('reorder_quantity')->default(10);
            $table->integer('max_stock_level')->nullable();
            $table->integer('reserved_quantity')->default(0);

            $table->integer('allocated_quantity')->default(0); // -- Allocated but not used
            $table->string('inventory_method')->default('fifo'); // -- fifo, lifo, weighted_average

            // Optional default target (e.g., a part kept for a specific asset or vehicle)
            $table->string('asset_type')->nullable();
            $table->uuid('asset_uuid')->nullable();
            $table->index(['asset_type', 'asset_uuid']);

            $table->foreignUuid('warranty_uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();

            $table->json('specs')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->timestamp('last_ordered_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_uuid', 'sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('parts');
        Schema::enableForeignKeyConstraints();
    }
};
