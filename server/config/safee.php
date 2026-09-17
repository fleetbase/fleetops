<?php

return [
    'polling_enabled'            => env('SAFEE_POLLING_ENABLED', true),
    'webhooks_enabled'           => false,
    'manual_batch_sync'          => true,
    'page_size'                  => 1000,
    'inventory_cache_seconds'    => env('SAFEE_INVENTORY_CACHE_SECONDS', 300),
    'request_timeout_seconds'    => env('SAFEE_REQUEST_TIMEOUT_SECONDS', 30),
];
