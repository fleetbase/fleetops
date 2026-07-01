<?php

test('fleet resource expands subfleet drivers and vehicles for hierarchy payloads', function () {
    $resource = file_get_contents(__DIR__ . '/../src/Http/Resources/v1/Fleet.php');

    expect($resource)
        ->toContain("if (in_array('subfleets', \$with, true))")
        ->toContain("if (in_array('drivers', \$with, true))")
        ->toContain("\$this->loadMissing('subFleets.drivers')")
        ->toContain("if (in_array('vehicles', \$with, true))")
        ->toContain("\$this->loadMissing('subFleets.vehicles')");
});
