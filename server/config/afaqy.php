<?php

return [
    'polling_enabled'           => env('AFAQY_POLLING_ENABLED', false),
    'webhooks_enabled'          => env('AFAQY_WEBHOOKS_ENABLED', false),
    'poll_queue'                => env('AFAQY_POLL_QUEUE', 'default'),
    'ingestion_queue'           => env('AFAQY_INGESTION_QUEUE', 'default'),
    'max_pages'                 => 100,
    'max_payload_bytes'         => 2097152,
    'max_pending_deliveries'    => 10000,
    'processed_retention_hours' => 24,
    'quarantine_retention_days' => 7,
    'stale_engine_on_seconds'   => 120,
    'stale_engine_off_seconds'  => 600,
];
