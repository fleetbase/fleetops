<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Concerns;

use Fleetbase\Support\Http;
use Illuminate\Support\Str;

/**
 * Renders a relationship for the public API as the related resource's public id.
 *
 * A caller that writes `vendor: "vendor_abc"` needs to read the assignment back
 * to confirm it persisted, and the public contract never exposes the uuid the
 * column actually holds. Asking for the relation through `?with=` still returns
 * the full nested object, and internal console requests keep the exact
 * `whenLoaded` shape they have always had.
 */
trait ResolvesPublicRelationFields
{
    /**
     * The relations the caller explicitly asked for, camelCased.
     *
     * @return array<int, string>
     */
    protected function requestedRelations($request): array
    {
        $with = $request->input('with');

        if (!is_array($with)) {
            return [];
        }

        return array_map(static fn ($relation) => Str::camel($relation), $with);
    }

    /**
     * @param string|null        $foreignKey the column holding the relation, when the
     *                                       relation is a belongsTo; null for the
     *                                       inverse side, which has no local column
     * @param array<int, string> $with       relations the caller asked for
     */
    protected function publicRelationField(string $relation, ?string $foreignKey, array $with, \Closure $resource): mixed
    {
        if (Http::isInternalRequest()) {
            return $this->whenLoaded($relation, $resource);
        }

        if (in_array($relation, $with, true)) {
            if (is_object($this->resource) && method_exists($this->resource, 'loadMissing')) {
                $this->loadMissing($relation);
            }

            return $this->{$relation} ? $resource() : null;
        }

        return $this->publicIdForRelation($relation, $foreignKey);
    }

    /**
     * The public id behind a relationship, or null when nothing is assigned.
     */
    protected function publicIdForRelation(string $relation, ?string $foreignKey): ?string
    {
        if ($foreignKey !== null && empty($this->{$foreignKey})) {
            return null;
        }

        $resource = $this->resource;

        if (is_object($resource) && method_exists($resource, 'relationLoaded') && $resource->relationLoaded($relation)) {
            return data_get($this, $relation . '.public_id');
        }

        // Not every resource is wrapped around an Eloquent model — webhook
        // payload fixtures and compact serializers pass plain objects — so read
        // the already-materialised value rather than calling a relation method
        // that is not there.
        if (!is_object($resource) || !method_exists($resource, $relation)) {
            return data_get($this, $relation . '.public_id');
        }

        return $this->{$relation}()->value('public_id');
    }
}
