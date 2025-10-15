<?php

namespace Fleetbase\FleetOps\Providers;

use Fleetbase\FleetOps\Support\Reporting\FleetOpsReportSchema;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Illuminate\Support\ServiceProvider;

class ReportSchemaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the FleetOps report schema
        $this->callAfterResolving(ReportSchemaRegistry::class, function (ReportSchemaRegistry $registry) {
            $schema = new FleetOpsReportSchema();
            $schema->registerReportSchema($registry);
        });
    }
}
