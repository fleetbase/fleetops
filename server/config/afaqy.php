<?php

return [
    'polling_enabled'           => env('AFAQY_POLLING_ENABLED', false),
    'webhooks_enabled'          => env('AFAQY_WEBHOOKS_ENABLED', false),
    'poll_queue'                => env('AFAQY_POLL_QUEUE', env('TELEMATICS_POLL_QUEUE', 'default')),
    'ingestion_queue'           => env('AFAQY_INGESTION_QUEUE', env('TELEMATICS_INGESTION_QUEUE', 'default')),
];
