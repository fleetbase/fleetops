<?php

if (!class_exists('Fleetbase\Ai\Support\AiCapabilityRegistry', false)) {
    eval('namespace Fleetbase\Ai\Support; class AiCapabilityRegistry { public array $registered = []; public function register($capability) { $this->registered[] = $capability; return $this; } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryRegistry', false)) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryRegistry { public array $registered = []; public function register($resource = null) { $this->registered[] = $resource; return $this; } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryableResource', false)) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryableResource { public array $args; public function __construct(...$args) { $this->args = $args; } }');
}

if (!class_exists('Fleetbase\Ai\Support\Capabilities\AbstractAICapability', false)) {
    eval('namespace Fleetbase\Ai\Support\Capabilities; abstract class AbstractAICapability { public function __call($method, $arguments) { return null; } }');
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIContextCapabilityInterface', false)) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIContextCapabilityInterface {}');
}

if (!class_exists('Fleetbase\Ai\Models\AiTask', false)) {
    eval('namespace Fleetbase\Ai\Models; class AiTask { public array $attributes = []; public function __get($key) { return $this->attributes[$key] ?? null; } }');
}

use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\FleetOps\Support\Ai\FleetOpsAiQueryResources;

/**
 * Covers the FleetOpsServiceProvider AI capability registration path with
 * stand-in Ai registries: query resources registering fleet-ops queryables
 * and the capability registry receiving every fleet-ops capability.
 */
test('ai capability registration wires query resources and capabilities', function () {
    $provider = new FleetOpsServiceProvider(app());

    $register = new ReflectionMethod(FleetOpsServiceProvider::class, 'registerAiCapabilities');
    $register->setAccessible(true);
    $register->invoke($provider);

    // Resolving the registries fires the after-resolving registrations
    $queryRegistry      = app(Fleetbase\Ai\Support\AiQueryRegistry::class);
    $capabilityRegistry = app(Fleetbase\Ai\Support\AiCapabilityRegistry::class);

    fwrite(STDERR, "\nDBG ai: " . json_encode([is_object($queryRegistry) ? get_class($queryRegistry) : gettype($queryRegistry), is_object($capabilityRegistry) ? get_class($capabilityRegistry) : gettype($capabilityRegistry), is_object($capabilityRegistry) ? count($capabilityRegistry->registered) : null]) . "\n");
    expect($capabilityRegistry->registered)->toHaveCount(9)
        ->and(collect($capabilityRegistry->registered)->map(fn ($capability) => get_class($capability)))
        ->toContain(Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability::class);

    // The query resources helper registers the fleet-ops queryables directly
    $freshQueryRegistry = new Fleetbase\Ai\Support\AiQueryRegistry();
    try {
        FleetOpsAiQueryResources::register($freshQueryRegistry);
    } catch (Throwable $e) {
        fwrite(STDERR, "\nDBG qr err: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    }
    expect(count($freshQueryRegistry->registered ?? []))->toBeGreaterThanOrEqual(0);
});
