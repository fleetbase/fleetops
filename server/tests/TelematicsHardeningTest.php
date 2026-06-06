<?php

test('device event model and migration expose lifecycle fields used by telematics workflows', function () {
    $model     = file_get_contents(__DIR__ . '/../src/Models/DeviceEvent.php');
    $migration = file_get_contents(__DIR__ . '/../migrations/2026_06_06_000001_harden_device_events_telematics_contract.php');

    expect($migration)
        ->toContain("'message'")
        ->toContain("'occurred_at'")
        ->toContain("'processed_at'")
        ->toContain("'data'");

    expect($model)
        ->toContain("'message'")
        ->toContain("'occurred_at'")
        ->toContain("'processed_at'")
        ->toContain("'data'")
        ->toContain("'occurred_at'     => 'datetime'")
        ->toContain("'processed_at'    => 'datetime'");
});

test('webhook controller resolves integrations explicitly and does not select the first provider record', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/TelematicWebhookController.php');

    expect($controller)
        ->toContain('$this->service->resolveWebhookTelematic($providerKey')
        ->toContain("'Unable to resolve telematic integration'")
        ->toContain('$result[\'devices\'] ?? []')
        ->toContain('$result[\'events\'] ?? []')
        ->toContain('$result[\'sensors\'] ?? []')
        ->toContain('Ambiguous telematic integration')
        ->toContain('validateWebhookSignature($request->getContent(), $signature')
        ->not->toContain("Telematic::where('provider', \$providerKey)\n            ->when")
        ->not->toContain("->first();\n\n        if (!\$telematic)");
});

test('telematics service requires provider identity and stores idempotent event keys', function () {
    $service = file_get_contents(__DIR__ . '/../src/Support/Telematics/TelematicService.php');

    expect($service)
        ->toContain('Provider device identity is required to link a telematics device.')
        ->toContain('DeviceEvent::firstOrNew([\'_key\' => $eventKey])')
        ->toContain('protected function makeEventKey')
        ->toContain('$telematic->public_id ?? $telematic->uuid')
        ->toContain('resolveWebhookTelematic')
        ->toContain('whereHas(\'device\'')
        ->toContain('meta->provider_account_id');
});

test('native providers normalize device payloads to canonical FleetOps keys', function () {
    $afaqy = file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/AfaqyProvider.php');
    $safee = file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/SafeeProvider.php');

    foreach ([$afaqy, $safee] as $provider) {
        expect($provider)
            ->toContain("'device_id'")
            ->toContain("'name'")
            ->toContain("'provider'")
            ->toContain("'model'")
            ->toContain("'online'")
            ->toContain("'last_seen_at'");
    }
});

test('telematics details use public id for consumer webhook URLs and do not read ember uuid', function () {
    $component = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.js');
    $template  = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.hbs');

    expect($component)
        ->toContain('const id = this.args.resource?.public_id;')
        ->toContain('return null;')
        ->not->toContain('this.args.resource?.uuid')
        ->not->toContain('this.args.resource?.public_id ?? this.args.resource?.id');

    expect($template)
        ->toContain('this.hasWebhookUrl')
        ->toContain('Webhook URL unavailable until this integration has a public ID.')
        ->toContain('last_sync_job_id')
        ->toContain('last_sync_error');
});
