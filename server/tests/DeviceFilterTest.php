<?php

test('device filter exposes telematics detail filter contract', function () {
    $filter     = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/DeviceFilter.php');
    $model      = file_get_contents(dirname(__DIR__) . '/src/Models/Device.php');
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/DeviceController.php');

    expect($model)
        ->toContain("'vehicle'")
        ->toContain("'connection_status'")
        ->toContain("'device_id'")
        ->toContain("'last_online_at'")
        ->toContain("'updated_at'");

    expect($filter)
        ->toContain('public function query(?string $searchQuery)')
        ->toContain('public function deviceId(?string $deviceId)')
        ->toContain("where('device_id', 'like'")
        ->toContain('public function vehicle(?string $vehicle)')
        ->toContain("where('attachable_uuid', \$vehicle)")
        ->toContain('public function connectionStatus')
        ->toContain("'online'")
        ->toContain("'recently_offline'")
        ->toContain("'offline'")
        ->toContain("'long_offline'")
        ->toContain("'never_connected'")
        ->toContain('public function lastOnlineAt')
        ->toContain('public function updatedAt')
        ->toContain('Utils::dateRange')
        ->toContain('protected function filterDate');

    expect($controller)
        ->toContain("filled('connection_status')")
        ->toContain("filled('last_online_at')")
        ->toContain("filled('updated_at')");
});
