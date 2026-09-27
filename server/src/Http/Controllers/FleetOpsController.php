<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\FleetbaseController;
use Illuminate\Database\Eloquent\Model;

class FleetOpsController extends FleetbaseController
{
    use AuthorizesMethods;

    /**
     * The package namespace used to resolve from.
     */
    public string $namespace = '\\Fleetbase\\FleetOps';

    /**
     * Explicit permissions for custom actions, keyed by controller method, e.g.
     * `['bulkDispatch' => 'dispatch order']`. Those methods also carry
     * `#[SkipAuthorizationCheck]` so AuthorizationGuard does not demand the
     * permission it would otherwise infer from the HTTP verb.
     *
     * @var array<string, string|string[]>
     */
    protected array $methodPermissions = [];

    public function __construct(?Model $model = null, ?string $resource = null)
    {
        parent::__construct($model, $resource);

        $this->authorizeMethods($this->methodPermissions);
    }
}
