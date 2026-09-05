<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns;

use Illuminate\Http\Request;

/**
 * Normalises the public `with` / `expand` parameters into an allowlisted set of
 * real Eloquent relation names, and writes the result back onto the request.
 *
 * Core's `HasApiModelBehavior` reads `with`/`expand` itself and hands the value
 * straight to `$result->load(...)`, with no allowlist and no mapping. Two things
 * follow from that. A caller can name any string and reach `load()`, and an
 * unknown one raises `RelationNotFoundException` — a 500 for a typo. And the
 * public name is not always the relation name: `?with=subfleets` normalises to
 * `subfleets`, while the relation is `subFleets`, so the documented expansion
 * has never worked.
 *
 * Rewriting the request once, up front, fixes both at the only point where the
 * query builder and the resource are guaranteed to agree on the list.
 */
trait ResolvesPublicExpansions
{
    /**
     * Resolve the requested expansions and rewrite the request with them.
     *
     * @param array<string, string> $allowed public name => Eloquent relation name
     *
     * @return array<int, string> the resolved relation names, in request order
     */
    protected function applyPublicExpansions(Request $request, array $allowed): array
    {
        $resolved = $this->resolvePublicExpansions($request, $allowed);

        // Both keys are rewritten: Core reads `with` or `expand`, whichever is
        // present, so leaving the other one holding raw input would put the
        // unmapped name back in front of load().
        $request->merge(['with' => $resolved]);

        if ($request->exists('expand')) {
            $request->merge(['expand' => $resolved]);
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $allowed
     *
     * @return array<int, string>
     */
    protected function resolvePublicExpansions(Request $request, array $allowed): array
    {
        $requested = $this->publicExpansionInput($request);
        $resolved  = [];

        foreach ($requested as $relation) {
            $mapped = $this->mapPublicExpansion($relation, $allowed);

            // An unsupported expansion is ignored rather than rejected. A 422
            // would turn a harmless unknown name into a failed request for
            // every generated client that sends a relation this version does
            // not have yet, and the alternative — passing it through — is a
            // 500 from Eloquent.
            if ($mapped !== null && !in_array($mapped, $resolved, true)) {
                $resolved[] = $mapped;
            }
        }

        return $resolved;
    }

    /**
     * Every accepted input shape flattened to one list of trimmed, non-empty paths.
     *
     * `?with=vendor`, `?with[]=vendor`, `?with=vendor,driver` and the `expand`
     * alias of each all arrive here and leave identical.
     *
     * @return array<int, string>
     */
    protected function publicExpansionInput(Request $request): array
    {
        $raw = $request->input('with');

        if ($raw === null || $raw === '' || $raw === []) {
            $raw = $request->input('expand');
        }

        if ($raw === null) {
            return [];
        }

        $values = [];
        foreach (is_array($raw) ? $raw : [$raw] as $entry) {
            if (is_array($entry)) {
                continue;
            }

            foreach (explode(',', (string) $entry) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }

        return $values;
    }

    /**
     * Map one public path onto its Eloquent relation path, or null if unsupported.
     *
     * Each dotted segment is resolved against the allowlist for the level it sits
     * at, so `subfleets.drivers` is checked as `subfleets` then as `drivers` —
     * a nested path cannot smuggle in a relation the top level would refuse.
     *
     * @param array<string, string> $allowed
     */
    protected function mapPublicExpansion(string $relation, array $allowed): ?string
    {
        $segments = explode('.', $relation);
        $mapped   = [];

        foreach ($segments as $index => $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                return null;
            }

            $candidates = $index === 0 ? $allowed : $this->nestedExpansionAllowList();
            $key        = $this->matchExpansionKey($segment, $candidates);

            if ($key === null) {
                return null;
            }

            $mapped[] = $candidates[$key];
        }

        return implode('.', $mapped);
    }

    /**
     * Relations reachable underneath an already-allowed relation.
     *
     * Deliberately shallow. Subfleets carry drivers and vehicles, which is the
     * documented nested case; nothing here re-opens the tree, so
     * `subfleets.subfleets` is refused and an expansion cannot recurse.
     *
     * @return array<string, string>
     */
    protected function nestedExpansionAllowList(): array
    {
        return [
            'drivers'  => 'drivers',
            'vehicles' => 'vehicles',
        ];
    }

    /**
     * Match a requested segment against the allowlist, spelling-insensitively.
     *
     * `service_area`, `serviceArea` and `ServiceArea` all name the same relation,
     * and so do `subfleets`, `subFleets` and `sub_fleets`. Comparing on
     * case-folded, separator-stripped forms accepts every spelling a client or a
     * generated SDK might produce without widening what is actually allowed.
     *
     * @param array<string, string> $candidates
     */
    private function matchExpansionKey(string $segment, array $candidates): ?string
    {
        foreach ($candidates as $key => $relation) {
            if ($this->expansionLookupKey($key) === $this->expansionLookupKey($segment)) {
                return $key;
            }
        }

        return null;
    }

    private function expansionLookupKey(string $value): string
    {
        return strtolower(str_replace(['_', '-'], '', $value));
    }
}
