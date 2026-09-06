<?php

namespace Fleetbase\FleetOps\Exceptions;

/**
 * Thrown when a public relationship input — `vendor`, `parent_fleet`, `zone`,
 * and friends — names a resource that does not exist inside the authenticated
 * company.
 *
 * A cross-company identifier is deliberately indistinguishable from a missing
 * one: both raise this, so the response cannot be used to probe whether some
 * other organization holds a given public id.
 */
class PublicRelationNotFoundException extends \Exception
{
    /**
     * The request key that failed to resolve, e.g. `parent_fleet`.
     */
    private string $relation;

    /**
     * The public identifier that was supplied for that key.
     */
    private ?string $identifier;

    public function __construct(string $relation, ?string $identifier = null, ?\Throwable $previous = null)
    {
        $this->relation   = $relation;
        $this->identifier = $identifier;

        parent::__construct(
            sprintf('No %s resource found for the identifier provided.', str_replace('_', ' ', $relation)),
            0,
            $previous
        );
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }
}
