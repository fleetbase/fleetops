<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\Models\Company;

class FleetOps
{
    /**
     * Creates or retrieves an existing transport configuration for a given company.
     *
     * The configuration comes from the `transport` order config template: an order is created,
     * dispatched, started, driven and completed.
     *
     * @param Company $company the company for which the transport configuration is being created or retrieved
     *
     * @return OrderConfig the transport configuration associated with the specified company
     */
    public static function createTransportConfig(Company $company): OrderConfig
    {
        return OrderConfigTemplates::create('transport', $company);
    }

    /**
     * Creates or retrieves the company's passenger configuration, from the `passenger` order
     * config template. Like transport it is a system config: it can't be deleted and its flow
     * is read-only.
     */
    public static function createPassengerConfig(Company $company): OrderConfig
    {
        return OrderConfigTemplates::create('passenger', $company);
    }

    /**
     * The order configs every company has: transport and passenger.
     *
     * @return array<string, OrderConfig>
     */
    public static function createDefaultConfigs(Company $company): array
    {
        return [
            'transport' => static::createTransportConfig($company),
            'passenger' => static::createPassengerConfig($company),
        ];
    }
}
