<?php

use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;

/**
 * Covers the registry's native-provider bootstrapping. Every other test builds
 * a registry against an empty `telematics.providers` config, so the descriptor
 * construction and registration inside the load loop never run.
 */
test('registry registers a descriptor for each configured native provider', function () {
    config()->set('telematics.providers', [
        [
            'key'                => 'acme_telematics',
            'label'              => 'Acme Telematics',
            'supports_webhooks'  => true,
            'supports_discovery' => false,
            'required_fields'    => ['api_key'],
        ],
        [
            'key'                => 'beta_telematics',
            'label'              => 'Beta Telematics',
            'type'               => 'custom',
            'supports_discovery' => true,
        ],
    ]);

    $registry = new TelematicProviderRegistry();

    expect($registry->all()->pluck('key')->all())->toBe(['acme_telematics', 'beta_telematics'])
        ->and($registry->findByKey('acme_telematics')->label)->toBe('Acme Telematics')
        ->and($registry->has('beta_telematics'))->toBeTrue()
        ->and($registry->has('missing_telematics'))->toBeFalse()
        ->and($registry->getWebhookProviders()->pluck('key')->all())->toBe(['acme_telematics'])
        ->and($registry->getDiscoveryProviders()->pluck('key')->all())->toBe(['beta_telematics'])
        ->and($registry->getNativeProviders()->pluck('key')->all())->toBe(['acme_telematics'])
        ->and($registry->getCustomProviders()->pluck('key')->all())->toBe(['beta_telematics']);

    config()->set('telematics.providers', []);
});
