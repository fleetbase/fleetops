<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\CompanyUser;
use Illuminate\Database\Migrations\Migration;
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
        Contact::withoutGlobalScopes()
            ->where('type', 'customer')
            ->whereNull('deleted_at')
            ->chunkById(100, function ($contacts) {
                foreach ($contacts as $contact) {
                    try {
                        $contact->repairCustomerTypeInvariant(true);
                    } catch (Throwable $e) {
                        // Skip ambiguous or unrecoverable records; runtime invariants handle new writes.
                    }
                }
            });

        if (!$this->canUseCustomerRoleHint()) {
            return;
        }

        DB::table('contacts')
            ->select('contacts.id')
            ->join('company_users', function ($join) {
                $join->on('company_users.user_uuid', '=', 'contacts.user_uuid');
                $join->on('company_users.company_uuid', '=', 'contacts.company_uuid');
            })
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_uuid', '=', 'company_users.uuid');
                $join->where('model_has_roles.model_type', CompanyUser::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Fleet-Ops Customer')
            ->whereNull('contacts.deleted_at')
            ->where(function ($query) {
                $query->whereNull('contacts.type');
                $query->orWhere('contacts.type', '!=', 'customer');
            })
            ->distinct()
            ->orderBy('contacts.id')
            ->chunk(100, function ($rows) {
                foreach ($rows as $row) {
                    $contact = Contact::withoutGlobalScopes()->find($row->id);
                    if (!$contact) {
                        continue;
                    }

                    try {
                        $contact->repairCustomerTypeInvariant(true);
                    } catch (Throwable $e) {
                        // Skip ambiguous or unrecoverable records; role is only a recovery hint.
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Data repair is intentionally not reversible.
    }

    private function canUseCustomerRoleHint(): bool
    {
        return Schema::hasTable('contacts')
            && Schema::hasTable('company_users')
            && Schema::hasTable('model_has_roles')
            && Schema::hasTable('roles');
    }
};
