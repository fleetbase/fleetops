<?php

namespace Fleetbase\FleetOps\Http\Filter\Concerns;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Support\Http;

/**
 * Resolves a relationship filter value expressed as a public identifier into the
 * internal uuids the column actually stores.
 *
 * The public API never hands out uuids, so `?vendor=vendor_abc` has to be
 * translated before it can be compared against `vendor_uuid`. Internal console
 * requests deliberately keep passing uuids straight through, so the uuid arm is
 * gated on the request kind rather than on the shape of the value.
 */
trait ResolvesPublicRelationUuids
{
    /**
     * Resolve one or more public identifiers to uuids within the session company.
     *
     * @param class-string              $modelClass  the model the identifiers belong to
     * @param string|array<int, string> $identifiers public_id / internal_id values (uuid when internal)
     *
     * @return array<int, string>
     */
    protected function resolvePublicRelationUuids(string $modelClass, string|array $identifiers, ?bool $allowUuid = null): array
    {
        $identifiers = array_values(array_filter(Utils::arrayFrom($identifiers), static fn ($identifier) => filled($identifier)));

        if ($identifiers === []) {
            return [];
        }

        $allowUuid = $allowUuid ?? Http::isInternalRequest($this->request);
        $instance  = new $modelClass();

        return $modelClass::query()
            ->where('company_uuid', $this->session->get('company'))
            ->where(function ($query) use ($identifiers, $instance, $allowUuid) {
                $query->whereIn('public_id', $identifiers);

                if (in_array('internal_id', $instance->getFillable())) {
                    $query->orWhereIn('internal_id', $identifiers);
                }

                if ($allowUuid) {
                    $query->orWhereIn('uuid', $identifiers);
                }
            })
            ->pluck('uuid')
            ->all();
    }
}
