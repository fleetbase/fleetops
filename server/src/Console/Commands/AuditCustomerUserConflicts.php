<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Console\Command;

class AuditCustomerUserConflicts extends Command
{
    protected $signature = 'fleetops:audit-customer-user-conflicts {--company= : Limit audit to a company UUID} {--json : Output JSON instead of a table}';

    protected $description = 'Reports customer contacts linked to users that may have been incorrectly promoted from staff/admin accounts.';

    public function handle(): int
    {
        $query = Contact::where('type', 'customer')->whereNotNull('user_uuid')->with(['anyUser.companyUsers.roles', 'company']);

        if ($company = $this->option('company')) {
            $query->where('company_uuid', $company);
        }

        $rows = $query->get()->map(function (Contact $contact) {
            $user        = $contact->anyUser;
            $companyUser = $user?->companyUsers?->firstWhere('company_uuid', $contact->company_uuid);
            $roles       = $companyUser?->roles?->pluck('name')->filter()->values()->all() ?? [];
            $unexpected  = array_values(array_filter($roles, fn ($role) => $role !== 'Fleet-Ops Customer'));
            $reasons     = [];

            if (!$user) {
                $reasons[] = 'missing linked user';
            } elseif ($user->type !== 'customer') {
                $reasons[] = 'linked user type is ' . $user->type;
            }

            if (!empty($unexpected)) {
                $reasons[] = 'linked user has non-customer roles: ' . implode(', ', $unexpected);
            }

            if (empty($reasons)) {
                return null;
            }

            return [
                'contact_id'    => $contact->public_id,
                'contact_uuid'  => $contact->uuid,
                'contact_name'  => $contact->name,
                'contact_email' => $contact->email,
                'user_uuid'     => $user?->uuid,
                'user_email'    => $user?->email,
                'user_type'     => $user?->type,
                'roles'         => implode(', ', $roles),
                'company_uuid'  => $contact->company_uuid,
                'company_name'  => $contact->company?->name,
                'updated_at'    => optional($contact->updated_at)->toDateTimeString(),
                'reason'        => implode('; ', $reasons),
            ];
        })->filter()->values();

        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info('No suspicious customer user conflicts found.');

            return self::SUCCESS;
        }

        $this->table(array_keys($rows->first()), $rows->all());

        return self::SUCCESS;
    }
}
