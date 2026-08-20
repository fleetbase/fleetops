<?php

namespace Fleetbase\FleetOps\Seeders\Demo\Concerns;

use Fleetbase\FleetOps\Seeders\Testing\Concerns\SeedsTestingData;

/**
 * The demo fixture set: same machinery as the testing one, different visible strings.
 *
 * Why a second set exists at all. The Testing fixtures are excellent structurally — real
 * Singapore coordinates, 25 vehicles with genuine makes and models, orders spread across the
 * status pipeline — but they are stamped throughout with text that announces itself as test
 * data: TEST- identifiers, @example.test addresses, "FleetOps testing fixture" notes. Those
 * strings are legible in a screenshot, and these fixtures feed the console screenshots
 * published on fleetbase.io.
 *
 * Everything else is inherited. `seedName()` is what keeps the two sets separately purgeable:
 * every record is tagged `meta->seed`, so seeding or purging one never touches the other, and
 * both can coexist in a single install.
 *
 * `seedName()` is a method rather than a redeclared constant because PHP rejects a class that
 * redeclares a trait constant with a different value ("the definition differs and is
 * considered incompatible"). See SeedsTestingData::seedName().
 */
trait SeedsDemoData
{
    use SeedsTestingData;

    protected function seedName(): string
    {
        return 'fleetops-demo';
    }

    protected function seedLabel(): string
    {
        return 'Fleetbase demo';
    }

    protected function identifierPrefix(): string
    {
        return 'FLT';
    }

    /**
     * Natural phrasing per record kind, because unlike the testing set these strings are read
     * by people looking at the marketing site rather than by an assertion.
     */
    protected function fixtureNote(string $noun): string
    {
        return [
            'order'                => 'Standard transport order.',
            'waypoint'             => 'Intermediate stop.',
            'item'                 => 'Standard parcel.',
            'contact'              => 'Primary account contact.',
            'vendor'               => 'Contracted logistics partner.',
            'zone'                 => 'Active operating zone.',
            'device'               => 'Installed telematics device.',
            'part'                 => 'Stocked spare part.',
            'maintenance schedule' => 'Recurring preventive maintenance.',
            'work order'           => 'Scheduled workshop job.',
            'maintenance history'  => 'Completed service record.',
        ][$noun] ?? ucfirst($noun) . '.';
    }
}
