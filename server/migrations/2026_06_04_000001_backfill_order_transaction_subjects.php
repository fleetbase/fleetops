<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('orders')
            ->whereNotNull('transaction_uuid')
            ->orderBy('id')
            ->select(['id', 'uuid', 'transaction_uuid', 'purchase_rate_uuid'])
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    $updates = [
                        'subject_uuid' => $order->uuid,
                        'subject_type' => Order::class,
                        'updated_at'   => now(),
                    ];

                    if ($order->purchase_rate_uuid) {
                        $updates['context_uuid'] = $order->purchase_rate_uuid;
                        $updates['context_type'] = PurchaseRate::class;
                    }

                    DB::table('transactions')
                        ->where('uuid', $order->transaction_uuid)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // Intentionally no-op: this migration corrects broken order transaction linkage.
    }
};
