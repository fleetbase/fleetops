<?php

return [
    'polling_enabled'           => false,
    'webhooks_enabled'          => false,
    'poll_queue'                => env('TELEMATICS_POLL_QUEUE', 'default'),
    'ingestion_queue'           => env('TELEMATICS_INGESTION_QUEUE', 'default'),
    'page_size'                 => 1000,
    'max_pages'                 => 100,
    'batch_size'                => 100,
    'max_payload_bytes'         => 2097152,
    'max_pending_deliveries'    => 10000,
    'processed_retention_hours' => 24,
    'quarantine_retention_days' => 7,
    'stale_engine_on_seconds'   => 120,
    'stale_engine_off_seconds'  => 600,
];
