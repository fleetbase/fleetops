<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;

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

test('native telematics providers expose local provider icons with a descriptor fallback', function () {
    $config    = include __DIR__ . '/../config/telematics.php';
    $iconPath  = '/engines-dist/images/telematics/providers/';
    $providers = array_filter($config['providers'], fn ($provider) => ($provider['type'] ?? 'native') === 'native');

    foreach ($providers as $provider) {
        $icon = $provider['icon'] ?? null;

        expect($icon)
            ->not->toBeNull()
            ->not->toContain('http://')
            ->not->toContain('https://');

        expect(str_starts_with($icon, $iconPath))->toBeTrue();
        expect(str_ends_with($icon, '.webp'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../../assets/images/telematics/providers/' . basename($icon)))->toBeTrue();
    }

    $safee = collect($providers)->firstWhere('key', 'safee');

    expect($safee['icon'])->toBe($iconPath . 'safee.webp');

    $descriptor = new TelematicProviderDescriptor([
        'key'   => 'custom',
        'label' => 'Custom',
    ]);

    expect($descriptor->icon)->toBe(TelematicProviderDescriptor::DEFAULT_ICON);
    expect(file_exists(__DIR__ . '/../../assets/images/telematics/providers/default.webp'))->toBeTrue();
});

test('device event mark processed action is routed and company scoped', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/DeviceEventController.php');

    expect($routes)
        ->toContain("\$router->fleetbaseRoutes('device-events', function")
        ->toContain("'{id}/mark-processed'")
        ->toContain("\$controller('markProcessed')");

    expect($controller)
        ->toContain('public function markProcessed(string $id): JsonResponse')
        ->toContain("where('company_uuid', session('company'))")
        ->toContain("where('uuid', \$id)->orWhere('public_id', \$id)")
        ->toContain('$deviceEvent->markAsProcessed()')
        ->toContain("'Event was already processed.'");
});

test('afaqy sync stores compact device diagnostics and does not leak token URLs', function () {
    $provider = file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/AfaqyProvider.php');

    expect($provider)
        ->toContain('compactLastUpdate')
        ->toContain("'provider_unit_id'")
        ->toContain("'plate_number'")
        ->toContain("'capabilities'")
        ->not->toContain("'raw'          => \$payload")
        ->not->toContain("'sensors',")
        ->not->toContain("?token=' . urlencode")
        ->toContain("'Authorization' => 'Bearer ' . \$token")
        ->toContain("array_merge(['token' => \$this->credentials['token']], \$payload)");
});

test('native endpoint fields are advanced optional overrides with provider defaults', function () {
    $config = include __DIR__ . '/../config/telematics.php';

    $afaqy = collect($config['providers'])->firstWhere('key', 'afaqy');
    $safee = collect($config['providers'])->firstWhere('key', 'safee');

    $afaqyBaseUrl   = collect($afaqy['required_fields'])->firstWhere('name', 'base_url');
    $safeeServerUri = collect($safee['required_fields'])->firstWhere('name', 'server_uri');

    foreach ([$afaqyBaseUrl, $safeeServerUri] as $field) {
        expect($field['required'])->toBeFalse();
        expect($field['advanced'])->toBeTrue();
        expect($field['is_endpoint'])->toBeTrue();
        expect($field['validation'])->toBe('nullable|url');
        expect($field['default_value'])->not->toBeEmpty();
    }
});

test('telematics activity logging excludes large json and spatial payloads', function () {
    $device      = telematics_activity_log_method(file_get_contents(__DIR__ . '/../src/Models/Device.php'));
    $sensor      = telematics_activity_log_method(file_get_contents(__DIR__ . '/../src/Models/Sensor.php'));
    $deviceEvent = telematics_activity_log_method(file_get_contents(__DIR__ . '/../src/Models/DeviceEvent.php'));

    foreach ([$device, $sensor, $deviceEvent] as $model) {
        expect($model)
            ->toContain('->logOnly([')
            ->toContain('->logOnlyDirty()')
            ->not->toContain('return LogOptions::defaults()->logAll();');
    }

    expect($device)
        ->not->toContain("'meta'")
        ->not->toContain("'data'")
        ->not->toContain("'last_position',");

    expect($sensor)
        ->not->toContain("'meta',")
        ->not->toContain("'last_position',");

    expect($deviceEvent)
        ->not->toContain("'payload',")
        ->not->toContain("'data',")
        ->not->toContain("'location',");
});

test('telematics setup renders endpoint overrides only in advanced settings', function () {
    $formTemplate      = file_get_contents(__DIR__ . '/../../addon/components/telematic/form.hbs');
    $settingsTemplate  = file_get_contents(__DIR__ . '/../../addon/components/telematic/settings.hbs');
    $formComponent     = file_get_contents(__DIR__ . '/../../addon/components/telematic/form.js');
    $settingsComponent = file_get_contents(__DIR__ . '/../../addon/components/telematic/settings.js');

    foreach ([$formComponent, $settingsComponent] as $component) {
        expect($component)
            ->toContain('advancedCredentialFields')
            ->toContain('field.advanced || field.is_endpoint')
            ->toContain('!field.advanced && !field.is_endpoint');
    }

    foreach ([$formTemplate, $settingsTemplate] as $template) {
        expect($template)
            ->toContain('Advanced connection settings')
            ->toContain('this.advancedCredentialFields')
            ->toContain('field.default_value');
    }
});

test('telematics details logs are routed and use persisted safe data only', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/TelematicController.php');

    expect($routes)
        ->toContain("'{id}/logs'")
        ->toContain("\$controller('logs')");

    expect($controller)
        ->toContain('public function logs(Request $request, string $id): JsonResponse')
        ->toContain('Activity::with([\'causer\'])')
        ->toContain("where('subject_type', Telematic::class)")
        ->toContain("where('subject_id', \$telematic->uuid)")
        ->toContain("where('company_uuid', session('company'))")
        ->toContain('Provider connection details were updated.')
        ->toContain('makeTelematicMetadataLogs')
        ->toContain('userFacingIssueMessage')
        ->toContain('isSensitiveIssueMessage')
        ->not->toContain('changed_fields')
        ->not->toContain('Str::headline')
        ->not->toContain('storage_path')
        ->not->toContain('Log::');
});

test('telematics provider status display maps active and connected to connected', function () {
    $indexController   = file_get_contents(__DIR__ . '/../../addon/controllers/connectivity/telematics/index.js');
    $detailsController = file_get_contents(__DIR__ . '/../../addon/controllers/connectivity/telematics/details.js');
    $statusCell        = file_get_contents(__DIR__ . '/../../addon/components/cell/telematic-status.js');

    expect($indexController)
        ->toContain("cellComponent: 'cell/telematic-status'");

    expect($detailsController)
        ->toContain("case 'active':\n                return 'Connected';");

    expect($statusCell)
        ->toContain("case 'active':")
        ->toContain("case 'connected':")
        ->toContain("return 'Connected';");
});

test('telematics overview attention items render as full width stacked alerts', function () {
    $template = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.hbs');

    expect($template)
        ->toContain('<section class="flex flex-col gap-3">')
        ->toContain('class="w-full rounded-md border border-yellow-200 bg-yellow-50 p-3')
        ->not->toContain('lg:grid-cols-2');
});

test('device attachment morph types are normalized and legacy aliases are tolerated', function () {
    $migration       = file_get_contents(__DIR__ . '/../migrations/2026_06_15_000001_normalize_device_attachable_vehicle_morph_types.php');
    $command         = file_get_contents(__DIR__ . '/../src/Console/Commands/FixInvalidPolymorphicRelationTypeNamespaces.php');
    $serviceProvider = file_get_contents(__DIR__ . '/../src/Providers/FleetOpsServiceProvider.php');

    expect($migration)
        ->toContain("whereNotNull('attachable_uuid')")
        ->toContain("'Fleetbase\\\\Models\\\\Vehicle'")
        ->toContain("'\\\\Fleetbase\\\\Models\\\\Vehicle'")
        ->toContain("'attachable_type' => Vehicle::class")
        ->toContain('Intentionally do not restore invalid legacy morph class names.');

    expect($command)
        ->toContain('\\Fleetbase\\FleetOps\\Models\\Device::class')
        ->toContain("'columns' => ['attachable_type']");

    expect($serviceProvider)
        ->toContain('use Illuminate\Database\Eloquent\Relations\Relation;')
        ->toContain('$this->registerMorphMap();')
        ->toContain("'Fleetbase\\\\Models\\\\Vehicle'   => \\Fleetbase\\FleetOps\\Models\\Vehicle::class")
        ->toContain("'\\\\Fleetbase\\\\Models\\\\Vehicle' => \\Fleetbase\\FleetOps\\Models\\Vehicle::class");
});

function telematics_activity_log_method(string $model): string
{
    preg_match('/public function getActivitylogOptions\(\): LogOptions\s+\{(?P<body>.*?)\n    \}/s', $model, $matches);

    return $matches['body'] ?? '';
}
