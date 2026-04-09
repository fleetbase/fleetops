<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;

/**
 * IntegratedVendor resolution logic for ServiceQuoteController.
 *
 * Split into two layers matching the pure/impure convention the Phase 2
 * bridge classes use:
 *
 *  - chooseVendorUuids(): pure static function that, given a list of
 *    candidate IntegratedVendor rows and a shipper client id, returns
 *    the list of vendor UUIDs to route the quote request through.
 *    Fully unit-testable without booting Laravel.
 *
 *  - resolveForQuoteRequest(): impure wrapper that queries
 *    IntegratedVendor via Eloquent, hands the candidate rows to the
 *    pure chooser, and returns the selected IntegratedVendor models.
 *    Used by ServiceQuoteController::queryRecord when the request
 *    carries no explicit facilitator.
 *
 * ## Resolution rule (enforced by chooseVendorUuids)
 *
 * For each distinct `provider` present in the candidate list:
 *   1. If shipperClientUuid is non-null AND a candidate exists with
 *      that exact shipper_client_uuid, pick it.
 *   2. Otherwise, if a catch-all candidate exists
 *      (shipper_client_uuid IS NULL), pick it.
 *   3. Otherwise, skip the provider silently. DO NOT route through
 *      a mismatched shipper_client_uuid — that would bill the wrong
 *      account, which is the entire reason this rule exists.
 *
 * When `providerFilter` is non-empty, only candidates whose provider
 * is in the filter are considered.
 */
class IntegratedVendorResolver
{
    /**
     * Given a list of candidate IntegratedVendor records (arrays with at
     * least `uuid`, `provider`, and `shipper_client_uuid` keys), return
     * the list of vendor UUIDs that should handle the quote request.
     *
     * @param array<int, array{uuid: string, provider: string, shipper_client_uuid: ?string}> $candidates
     * @param ?string             $shipperClientUuid Vendor uuid of the order's shipper-client, or null.
     * @param ?array<int, string> $providerFilter    When non-empty, restricts to these provider codes only.
     *
     * @return array<int, string> ordered by the iteration order of $candidates; deterministic
     */
    public static function chooseVendorUuids(
        array $candidates,
        ?string $shipperClientUuid = null,
        ?array $providerFilter = null
    ): array {
        // Normalize provider filter: null or [] => no filter.
        $filterActive = is_array($providerFilter) && count($providerFilter) > 0;
        $filterSet = $filterActive
            ? array_flip(array_map('strval', $providerFilter))
            : null;

        // Bucket candidates by provider, preserving first-seen order so the
        // final result is deterministic regardless of how the DB returned rows.
        $byProvider = [];
        $providerOrder = [];
        foreach ($candidates as $candidate) {
            $provider = isset($candidate['provider']) ? (string) $candidate['provider'] : '';
            if ($provider === '') {
                continue;
            }
            if ($filterActive && !isset($filterSet[$provider])) {
                continue;
            }
            if (!isset($byProvider[$provider])) {
                $byProvider[$provider] = [];
                $providerOrder[] = $provider;
            }
            $byProvider[$provider][] = $candidate;
        }

        $chosen = [];
        foreach ($providerOrder as $provider) {
            $group = $byProvider[$provider];

            // Prefer a client-specific match when the request carries a
            // shipperClientUuid. Matching is exact-string.
            $clientSpecific = null;
            $catchAll = null;
            foreach ($group as $candidate) {
                $rowShipper = $candidate['shipper_client_uuid'] ?? null;
                if ($shipperClientUuid !== null && $rowShipper === $shipperClientUuid) {
                    $clientSpecific = $candidate;
                    break; // first match wins; no need to scan further for this provider
                }
                if ($catchAll === null && $rowShipper === null) {
                    $catchAll = $candidate;
                }
            }

            $pick = $clientSpecific ?? $catchAll;
            if ($pick !== null && isset($pick['uuid'])) {
                $chosen[] = (string) $pick['uuid'];
            }
            // else: neither a client-specific match nor a catch-all exists
            //       for this provider — skip it silently per the resolution
            //       rule. Never route through a mismatched
            //       shipper_client_uuid; that would bill the wrong account.
        }

        return $chosen;
    }

    /**
     * Impure wrapper: query IntegratedVendor records scoped to a company,
     * run the pure chooser, and hydrate back to IntegratedVendor models
     * ready for ->api()->getQuoteFromPayload(...).
     *
     * The order's `customer_type`/`customer_uuid` determines the shipper
     * client uuid when the customer is a Vendor; otherwise null is passed
     * and only catch-all credentials are considered.
     *
     * @param string              $companyUuid    the fleetops company_uuid scoping the lookup
     * @param ?Order              $order          the order this quote request is for, if available
     * @param ?array<int, string> $providerFilter optional list of provider codes to restrict to
     *
     * @return array<int, IntegratedVendor>
     */
    public static function resolveForQuoteRequest(
        string $companyUuid,
        ?Order $order = null,
        ?array $providerFilter = null
    ): array {
        // Pull every IntegratedVendor the company owns, then let the pure
        // chooser apply the resolution rule. This is fine for Phase 2 scale
        // (a broker has at most a few dozen carrier credential rows); if it
        // becomes a hot path later, swap in a WHERE IN (provider_filter)
        // query with a composite index hit on (company_uuid, provider,
        // shipper_client_uuid).
        $query = IntegratedVendor::where('company_uuid', $companyUuid);
        if (is_array($providerFilter) && count($providerFilter) > 0) {
            $query->whereIn('provider', $providerFilter);
        }
        $rows = $query->get(['uuid', 'provider', 'shipper_client_uuid']);

        $candidates = $rows->map(fn ($row) => [
            'uuid'                => (string) $row->uuid,
            'provider'            => (string) $row->provider,
            'shipper_client_uuid' => $row->shipper_client_uuid !== null
                ? (string) $row->shipper_client_uuid
                : null,
        ])->all();

        $shipperClientUuid = null;
        if ($order !== null && ($order->customer_type === 'vendor' || str_contains((string) $order->customer_type, 'Vendor'))) {
            $shipperClientUuid = $order->customer_uuid !== null ? (string) $order->customer_uuid : null;
        }

        $chosenUuids = self::chooseVendorUuids($candidates, $shipperClientUuid, $providerFilter);
        if (empty($chosenUuids)) {
            return [];
        }

        // Preserve the ordering produced by chooseVendorUuids so the
        // ServiceQuote response has a stable sort.
        $indexed = IntegratedVendor::whereIn('uuid', $chosenUuids)->get()->keyBy('uuid');
        $resolved = [];
        foreach ($chosenUuids as $uuid) {
            if (isset($indexed[$uuid])) {
                $resolved[] = $indexed[$uuid];
            }
        }

        return $resolved;
    }
}
