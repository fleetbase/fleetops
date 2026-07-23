<?php

declare(strict_types=1);

$autoloadCandidates = [
    getcwd() . '/server_vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}

if (class_exists('Illuminate\Container\Container') && class_exists('Illuminate\Support\Facades\Facade')) {
    $app = Illuminate\Container\Container::getInstance();
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);

    if (!$app->bound('config') && class_exists('Illuminate\Config\Repository')) {
        $app->singleton('config', fn () => new Illuminate\Config\Repository([
            'fleetops'   => [],
            'services'   => [],
            'telematics' => [],
        ]));
    }

    if (!$app->bound(Illuminate\Contracts\Routing\ResponseFactory::class)) {
        $responseFactory = new class {
            public function json(mixed $data = [], int $status = 200): mixed
            {
                if (class_exists('Illuminate\Http\JsonResponse')) {
                    return new Illuminate\Http\JsonResponse($data, $status);
                }

                return new class($data, $status) {
                    public function __construct(public mixed $data, public int $status)
                    {
                    }

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }
                };
            }

            public function error(mixed $error = null, int $status = 500): mixed
            {
                return $this->json(['error' => $error], $status);
            }
        };

        $app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $responseFactory);
        $app->instance('Illuminate\Contracts\Routing\ResponseFactory', $responseFactory);
        $app->instance('response', $responseFactory);
    }

    if (!$app->bound('http') && class_exists('Illuminate\Http\Client\Factory')) {
        $app->singleton('http', fn () => new Illuminate\Http\Client\Factory());
    }

    if (!$app->bound('cache') && class_exists('Illuminate\Cache\Repository') && class_exists('Illuminate\Cache\ArrayStore')) {
        $app->singleton('cache.store', fn () => new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
        $app->singleton('cache', fn ($app) => $app->make('cache.store'));
    }

    if (!$app->bound('session.store') && class_exists('Illuminate\Session\Store') && class_exists('Illuminate\Session\ArraySessionHandler')) {
        $app->singleton('session.store', fn () => new Illuminate\Session\Store('pest', new Illuminate\Session\ArraySessionHandler(120)));
        $app->singleton('session', fn ($app) => $app->make('session.store'));
    }

    if (!$app->bound('log') && class_exists('Psr\Log\NullLogger')) {
        if (!class_exists('Fleetbase\TestSupport\LoggerManager')) {
            eval('namespace Fleetbase\TestSupport; class LoggerManager extends \Psr\Log\NullLogger { public function channel(?string $name = null): self { return $this; } }');
        }

        $app->singleton('log', fn () => new Fleetbase\TestSupport\LoggerManager());
    }
}

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        if (class_exists('Illuminate\Container\Container')) {
            $container = Illuminate\Container\Container::getInstance();

            return $abstract === null ? $container : $container->make($abstract, $parameters);
        }

        return $abstract === null ? null : new $abstract(...array_values($parameters));
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        if (class_exists('Illuminate\Container\Container')) {
            $container = Illuminate\Container\Container::getInstance();

            if ($container->bound('request')) {
                $request = $container->make('request');

                return $key === null ? $request : $request->input($key, $default);
            }
        }

        $request = class_exists('Illuminate\Http\Request') ? Illuminate\Http\Request::create('/') : new stdClass();

        return $key === null ? $request : $default;
    }
}

if (!function_exists('response')) {
    function response(): object
    {
        return new class {
            public function json(mixed $data = [], int $status = 200): mixed
            {
                if (class_exists('Illuminate\Http\JsonResponse')) {
                    return new Illuminate\Http\JsonResponse($data, $status);
                }

                return new class($data, $status) {
                    public function __construct(public mixed $data, public int $status)
                    {
                    }

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }
                };
            }

            public function error(mixed $error = null, int $status = 500): mixed
            {
                return $this->json(['error' => $error], $status);
            }
        };
    }
}

if (!function_exists('session')) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        static $values = [];

        if (is_array($key)) {
            $values = array_merge($values, $key);

            return null;
        }

        return $key === null ? $values : ($values[$key] ?? $default);
    }
}

if (!function_exists('now') && class_exists('Illuminate\Support\Carbon')) {
    function now($tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}

if (!trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
    eval('namespace Illuminate\Foundation\Auth\Access; trait AuthorizesRequests {}');
}

if (!trait_exists('Illuminate\Foundation\Bus\Dispatchable')) {
    eval('namespace Illuminate\Foundation\Bus; trait Dispatchable {}');
}

if (!trait_exists('Illuminate\Foundation\Bus\DispatchesJobs')) {
    eval('namespace Illuminate\Foundation\Bus; trait DispatchesJobs {}');
}

if (!trait_exists('Illuminate\Foundation\Validation\ValidatesRequests')) {
    eval('namespace Illuminate\Foundation\Validation; trait ValidatesRequests {}');
}

if (!trait_exists('Fleetbase\Traits\HasApiModelCache')) {
    eval('namespace Fleetbase\Traits; trait HasApiModelCache {}');
}

if (!trait_exists('Fleetbase\Traits\HasCustomFields')) {
    eval('namespace Fleetbase\Traits; trait HasCustomFields {}');
}

if (!class_exists('Illuminate\Foundation\Http\FormRequest') && class_exists('Illuminate\Http\Request')) {
    eval('namespace Illuminate\Foundation\Http; class FormRequest extends \Illuminate\Http\Request { public function authorize(): bool { return true; } public function rules(): array { return []; } public function responseWithErrors(\Illuminate\Contracts\Validation\Validator $validator) { return $validator; } }');
}

if (!class_exists('Fleetbase\Models\ScheduleItem') && class_exists('Fleetbase\Models\Model')) {
    eval('namespace Fleetbase\Models; class ScheduleItem extends Model {}');
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIContextCapabilityInterface')) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIContextCapabilityInterface {}');
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIActionCapabilityInterface')) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIActionCapabilityInterface {}');
}

if (!class_exists('Fleetbase\Ai\Models\AiTask')) {
    eval('namespace Fleetbase\Ai\Models; class AiTask { public function __construct(array $attributes = []) { foreach ($attributes as $key => $value) { $this->{$key} = $value; } } }');
}

if (!class_exists('Fleetbase\Ai\Support\Capabilities\AbstractAICapability')) {
    eval('namespace Fleetbase\Ai\Support\Capabilities; abstract class AbstractAICapability {}');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryableResource')) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryableResource { public string $key; public array $fields; public array $aliases; public function __construct(string $key, string $label = "", string $module = "", string $modelClass = "", string $permission = "", array $aliases = [], array $fields = [], array $sampleFields = [], ?string $locationField = null, ?string $directivePermission = null, int $maxLimit = 100) { $this->key = $key; $this->fields = $fields; $this->aliases = $aliases; } public function hasField(string $field): bool { return array_key_exists($field, $this->fields); } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryRegistry')) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryRegistry { private array $resources = []; public function register(AiQueryableResource $resource): void { $this->resources[$resource->key] = $resource; foreach ($resource->aliases as $alias) { $this->resources[$alias] = $resource; } } public function find(string $key): ?AiQueryableResource { return $this->resources[$key] ?? null; } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiRelativeDateResolver') && class_exists('Illuminate\Support\Carbon')) {
    eval('namespace Fleetbase\Ai\Support; class AiRelativeDateResolver { public function __construct($parser = null) {} public function resolveDateTime(string $prompt, ?string $timezone = null): ?\Illuminate\Support\Carbon { if (preg_match("/(\d+)\s+days?\s+from\s+now/i", $prompt, $matches)) { return \Illuminate\Support\Carbon::now($timezone)->addDays((int) $matches[1]); } return null; } public function resolveWindow(string $prompt, ?string $timezone = null): ?array { $timezone = $timezone ?: date_default_timezone_get(); $now = \Illuminate\Support\Carbon::now($timezone); if (str_contains(strtolower($prompt), "last week")) { $start = $now->copy()->subWeek()->startOfWeek(); $end = $now->copy()->subWeek()->endOfWeek(); return ["label" => "last week", "timezone" => $timezone, "start" => $start, "end" => $end]; } if (str_contains(strtolower($prompt), "yesterday")) { $start = $now->copy()->subDay()->startOfDay(); $end = $now->copy()->subDay()->endOfDay(); return ["label" => "yesterday", "timezone" => $timezone, "start" => $start, "end" => $end]; } return null; } }');
}

set_error_handler(function (int $severity, string $message): bool {
    if (str_contains($message, '/pestphp/pest/vendor/autoload.php')) {
        return true;
    }

    return false;
}, E_WARNING);
