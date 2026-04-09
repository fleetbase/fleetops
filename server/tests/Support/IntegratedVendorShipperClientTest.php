<?php

use Fleetbase\FleetOps\Models\IntegratedVendor;

/**
 * Reflection-based assertions — the IntegratedVendor constructor calls
 * config('fleetbase.db.connection') and cannot be instantiated under
 * plain Pest without booting Laravel. We inspect the protected
 * $fillable array and the class's declared methods directly.
 */
function ivFillable(): array
{
    $ref = new ReflectionClass(IntegratedVendor::class);
    $defaults = $ref->getDefaultProperties();
    return $defaults['fillable'] ?? [];
}

test('IntegratedVendor fillable includes shipper_client_uuid', function () {
    expect(ivFillable())->toContain('shipper_client_uuid');
});

test('IntegratedVendor fillable still includes company_uuid (no regression)', function () {
    expect(ivFillable())->toContain('company_uuid');
});

test('IntegratedVendor has shipperClient relationship method', function () {
    expect(method_exists(IntegratedVendor::class, 'shipperClient'))->toBeTrue();
});

test('shipperClient method signature exists via reflection', function () {
    $ref = new ReflectionClass(IntegratedVendor::class);
    $method = $ref->getMethod('shipperClient');
    expect($method->isPublic())->toBeTrue();
    expect($method->isStatic())->toBeFalse();
});
