<?php

test('getting started status route is registered', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)
        ->toContain("['prefix' => 'getting-started']")
        ->toContain("\$router->get('status', 'GettingStartedController@status');");
});

test('getting started support exposes checklist and generic recommendations', function () {
    $support = file_get_contents(dirname(__DIR__) . '/src/Support/GettingStarted.php');

    expect($support)
        ->toContain("'key'         => 'add_driver'")
        ->toContain("'key'         => 'create_order'")
        ->toContain("'key'         => 'assign_driver'")
        ->toContain("'key'         => 'update_activity'")
        ->toContain("'profile_source'  => 'generic'")
        ->toContain("'recommendations' => \$this->recommendations()");
});
