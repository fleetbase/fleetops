<?php

return [
    'polling_enabled'           => false,
    'webhooks_enabled'          => false,
    'poll_queue'                => env('TELEMATICS_POLL_QUEUE', 'default'),
    'ingestion_queue'           => env('TELEMATICS_INGESTION_QUEUE', 'default'),
    // Null keeps telemetry broadcasts on the connection's default queue.
    'broadcast_queue'           => env('TELEMATICS_BROADCAST_QUEUE'),
    'page_size'                 => 1000,
    'max_pages'                 => 100,
    'request_timeout_seconds'   => env('TELEMATICS_REQUEST_TIMEOUT_SECONDS', 45),
    'batch_size'                => env('TELEMATICS_BATCH_SIZE', 100),
    'max_payload_bytes'         => 2097152,
    'max_pending_deliveries'    => 10000,
    // Retention defaults. Companies override these from Fleet-Ops > Settings > Telematics;
    // administrators override the system defaults from the admin console. 0 keeps rows forever.
    'processed_retention_hours' => 24,
    'quarantine_retention_days' => 7,
    'sync_run_retention_days'   => 7,
    'event_retention_days'      => 30,
    'event_compact_after_days'  => 7,
    'position_retention_days'   => 90,
    'log_telemetry_activity'    => false,
    'prune_batch_size'          => 1000,
    'prune_max_batches'         => 50,
    'stale_engine_on_seconds'   => 120,
    'stale_engine_off_seconds'  => 600,
];
