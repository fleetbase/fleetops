<?php

use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Support\InspectionFormSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Folds the first cut's `items` checklist into the second cut's structure.
 *
 * A form used to be a JSON list of pass/fail items; it is now groups of typed
 * fields. Rather than ask an operator to rebuild every form by hand, each
 * legacy checklist becomes a "Checklist" group of `pass-fail` fields carrying
 * the same labels and severities. `items` is left in place, read-only, for one
 * release, so a console or app that has not been updated still renders.
 *
 * Idempotent: a form that already has fields is skipped, so re-running this —
 * or running it after a form has been rebuilt in the builder — changes
 * nothing. There is no down: dropping fields that an operator may since have
 * edited would lose work, and `items` was never removed.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('inspection_forms') || !Schema::hasTable('custom_fields') || !Schema::hasTable('categories')) {
            return;
        }

        InspectionForm::query()
            ->whereNotNull('items')
            ->orderBy('id')
            ->chunkById(100, function ($forms) {
                foreach ($forms as $form) {
                    InspectionFormSync::convertLegacyItems($form);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: `items` was never removed, and the fields written
        // here may have been edited since.
    }
};
