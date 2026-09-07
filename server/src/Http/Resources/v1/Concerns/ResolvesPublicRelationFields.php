<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Concerns;

use Fleetbase\Support\Http;
use Illuminate\Http\Resources\MissingValue;

/**
 * Renders a relationship twice: as an additive `<name>_id` public identifier, and
 * as the nested object under the name the endpoint has always used.
 *
 * The two are deliberately separate keys. The SDK stores whatever the API returns
 * verbatim — `Resource::$attributes = $attributes`, no normalisation — so a
 * property that is an object on one call and a string on another silently breaks
 * every consumer that dereferences it. Navigator interpolates `driver.user`
 * straight into a socket channel name; an object there would subscribe it to
 * `user.[object Object]` and simply stop delivering messages. So an existing
 * object key stays an object key, always, and the identifier arrives beside it.
 */
trait ResolvesPublicRelationFields
{
    /**
     * The relations the caller asked for, as real Eloquent relation names.
     *
     * The controller normalises, maps and allowlists `with` / `expand` for the
     * resource it returns. That guarantee does not travel: this resource is also
     * rendered nested inside Order and Driver responses, where the request's
     * `with` names the parent's relations (`payload`, `driverAssigned.vehicle`).
     * A nested resource re-reads the same request, so only relations the wrapped
     * model actually defines are kept — a parent's expansion must never reach
     * this model's loader, where it raises RelationNotFoundException.
     *
     * @return array<int, string>
     */
    protected function requestedRelations($request): array
    {
        $with = $request->input('with');

        if (is_string($with)) {
            $with = explode(',', $with);
        }

        if (!is_array($with)) {
            return [];
        }

        $with = array_values(array_filter(array_map('strval', $with), 'strlen'));

        return array_values(array_filter($with, fn (string $relation): bool => $this->definesRelation($relation)));
    }

    /**
     * Whether the wrapped model defines the root of a relation path.
     *
     * Only the first segment is checked: nested segments belong to the related
     * model, and Eloquent validates those itself once the root exists.
     */
    private function definesRelation(string $relation): bool
    {
        $resource = $this->resource;

        if (!is_object($resource) || !method_exists($resource, 'loadMissing')) {
            return false;
        }

        $root = explode('.', $relation, 2)[0];

        return $root !== '' && method_exists($resource, $root);
    }

    /**
     * The nested object for a relationship.
     *
     * Internal console requests keep the exact `whenLoaded` behaviour they have
     * always had. Public requests get the object only when they asked for it,
     * which is also what they got before: these relations were never eager
     * loaded on the public endpoints, so the key was absent unless `with` named
     * it. Absent stays absent; it never becomes a string.
     *
     * @param array<int, string> $with camelCased relations the caller asked for
     */
    protected function publicRelationObject(string $relation, array $with, \Closure $resource): mixed
    {
        if (Http::isInternalRequest()) {
            return $this->whenLoaded($relation, $resource);
        }

        if (!in_array($relation, $with, true)) {
            return new MissingValue();
        }

        $this->loadRelationIfPossible($relation);

        return $this->{$relation} ? $resource() : null;
    }

    /**
     * The public id behind a relationship, or null when nothing is assigned.
     *
     * Additive and unconditional: it is present whether or not the object is,
     * and it does not change when the object is expanded.
     *
     * @param string|null $foreignKey the column holding the relation for a belongsTo;
     *                                null for the inverse side, which has no local column
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

        // Not every resource wraps an Eloquent model — webhook payload fixtures and
        // compact serializers pass plain objects — so read the already-materialised
        // value rather than calling a relation method that is not there.
        if (!is_object($resource) || !method_exists($resource, $relation)) {
            return data_get($this, $relation . '.public_id');
        }

        return $this->{$relation}()->value('public_id');
    }

    private function loadRelationIfPossible(string $relation): void
    {
        $resource = $this->resource;

        if (is_object($resource) && method_exists($resource, 'loadMissing') && method_exists($resource, $relation)) {
            $this->loadMissing($relation);
        }
    }
}
