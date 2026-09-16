<?php

namespace Fleetbase\FleetOps\Contracts;

/** Optional extension: existing integrations need not change their provider contract. */
interface TelemetryProviderInterface extends TelematicProviderInterface
{
    /** Runtime switches and protocol-specific limits; must not authenticate. */
    public function telemetryOptions(): array;

    /** Split a provider delivery into raw position samples; reject unsupported envelopes. */
    public function telemetryUnits(array $payload): array;

    /**
     * Normalize one raw sample without network access using existing device/event/sensor fields.
     *
     * device.device_id and event.device_id identify the same unit within this connection.
     * event includes event_type, occurred_at (device time), last_seen_at (contact time),
     * location {lat, lng}, and optional meta.telemetry.provider_at. Missing/invalid
     * timestamps or coordinates must remain null; never replace them with receipt time.
     * Each sensor includes recorded_at in UTC and its existing normalized identity fields.
     * Preserve omissions in partial messages, rather than synthesizing metadata defaults.
     *
     * @return array{device: array, event: array, sensors?: array}
     */
    public function normalizeTelemetrySnapshot(array $payload): array;
}
