<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Delete malformed AFAQY telematics sensor rows created before sensor identities
     * were normalized. AFAQY remains the source of truth and the next sync recreates
     * one clean latest-state row per real provider sensor.
     */
    public function up(): void
    {
        DB::table('sensors')
            ->whereIn('telematic_uuid', function ($query) {
                $query->select('uuid')
                    ->from('telematics')
                    ->where('provider', 'afaqy');
            })
            ->delete();

        DB::table('sensors')
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.provider'))) = ?", ['afaqy'])
            ->delete();
    }

    /**
     * This cleanup cannot restore deleted rows. Run an AFAQY sync to recreate
     * current sensor state from the provider.
     */
    public function down(): void
    {
    }
};
