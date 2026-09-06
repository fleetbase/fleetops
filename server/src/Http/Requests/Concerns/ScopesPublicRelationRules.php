<?php

namespace Fleetbase\FleetOps\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Builds `exists` rules for public relationship inputs that are scoped to the
 * authenticated company.
 *
 * An unscoped `exists:vendors,public_id` accepts another organization's public
 * id, and the controller then resolves it to nothing — so the request looks
 * accepted while the relationship is silently dropped. Scoping the rule turns
 * that into an explicit validation failure, and keeps a public id from
 * confirming or denying the existence of a resource in someone else's company.
 */
trait ScopesPublicRelationRules
{
    /**
     * An `exists` rule limited to the session company and to rows that are not
     * soft deleted.
     *
     * No return type: the pest harness substitutes its own `Illuminate\Validation\Rule`
     * stand-in, which is not an instance of the real `Rules\Exists`.
     */
    protected function existsInCompany(string $table, string $column = 'public_id')
    {
        $companyUuid = session('company');

        return Rule::exists($table, $column)->where(function ($query) use ($companyUuid) {
            $query->where('company_uuid', $companyUuid);
            $query->whereNull('deleted_at');
        });
    }

    /**
     * The full rule set for an optional public relationship input.
     *
     * `nullable` is deliberate: sending `null` is how a caller clears an
     * existing assignment, and `exists` is skipped for a null value.
     *
     * @return array<int, mixed>
     */
    protected function publicRelationRules(string $table, string $column = 'public_id'): array
    {
        return ['nullable', 'string', $this->existsInCompany($table, $column)];
    }
}
