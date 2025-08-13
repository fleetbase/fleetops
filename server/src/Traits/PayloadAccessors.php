<?php

namespace Fleetbase\FleetOps\Traits;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PayloadAccessors.
 *
 * Drop this trait into the model that has the `payload()` relationship and a `payload_uuid` FK.
 * It provides:
 *  - getPayload(): returns the related Payload if present (with global scopes applied)
 *  - getTrashedPayload(): returns the related Payload without global scopes (e.g., soft-deleted)
 *  - getOrder(): returns the Order associated through Payload (or null)
 *
 * Design notes / best practices:
 *  - Avoids unconditional eager loading; checks relation cache first via relationLoaded().
 *  - Uses the relationship query as the primary source of truth, falling back to a direct lookup by UUID.
 *  - When resolving via direct lookup, caches the relation with setRelation() to prevent repeat queries.
 *  - Keeps the “ignore global scopes” behavior in a single private helper.
 *
 * Assumptions:
 *  - The host model defines `public function payload(): BelongsTo`.
 *  - `payload_uuid` stores a UUID, and `Payload`’s primary key is configured for UUIDs (so `find()` works).
 */
trait PayloadAccessors
{
    /**
     * Get the associated Payload with global scopes applied.
     *
     * @return \App\Models\Payload|null
     */
    public function getPayload(): ?Payload
    {
        return $this->resolvePayload(ignoreGlobalScopes: false);
    }

    /**
     * Get the associated Payload without global scopes (e.g., include soft-deleted).
     *
     * @return \App\Models\Payload|null
     */
    public function getTrashedPayload(): ?Payload
    {
        return $this->resolvePayload(ignoreGlobalScopes: true);
    }

    /**
     * Convenience accessor: get the Order via the associated Payload.
     *
     * @return \App\Models\Order|null
     */
    public function getOrder(): ?Order
    {
        // Leverage PHP nullsafe operator; no extra branching needed.
        return $this->getPayload()?->order;
    }

    /**
     * Core resolver for the Payload relation.
     *
     * Strategy:
     *  1) If the relation is already loaded and we are NOT ignoring global scopes, return it.
     *  2) Otherwise, query via the relationship, optionally disabling global scopes.
     *  3) If still not found and payload_uuid looks like a UUID, do a direct lookup on Payload,
     *     optionally disabling global scopes. If found, cache back into the relation.
     *
     * @param bool $ignoreGlobalScopes when true, fetch via `withoutGlobalScopes()`
     *
     * @return \App\Models\Payload|null
     */
    protected function resolvePayload(bool $ignoreGlobalScopes = false): ?Payload
    {
        // (1) If relation is already loaded and we accept scoped data, return it.
        if (!$ignoreGlobalScopes && $this->relationLoaded('payload')) {
            $related = $this->getRelation('payload');
            if ($related instanceof Payload) {
                return $related;
            }
        }

        // (2) Query through the relationship (preferred, keeps FK semantics consistent).
        $relation = $this->payload();
        $query    = $ignoreGlobalScopes ? $relation->withoutGlobalScopes() : $relation;

        /** @var \App\Models\Payload|null $payload */
        $payload = $query->first();

        if ($payload instanceof Payload) {
            // Cache the resolved relation for subsequent access on this model instance.
            $this->setRelation('payload', $payload);

            return $payload;
        }

        // (3) Fallback: direct lookup by UUID (useful if relation is not properly hydrated).
        $uuid = $this->payload_uuid ?? null;
        if ($uuid && Str::isUuid($uuid)) {
            $payloadQuery = Payload::query();
            if ($ignoreGlobalScopes) {
                $payloadQuery->withoutGlobalScopes();
            }

            $payload = $payloadQuery->find($uuid); // relies on Payload PK being 'uuid'

            if ($payload instanceof Payload) {
                // Keep the relation cache consistent for this instance.
                $this->setRelation('payload', $payload);

                return $payload;
            }
        }

        return null;
    }

    /**
     * Relationship stub (documentational).
     * Ensure your model actually defines this in the host model—not strictly required here,
     * but included as a guide for expected signature.
     */
    abstract public function payload(): BelongsTo;
}
