<?php

namespace Fleetbase\FleetOps\Jobs {
    // Advance the polling deadline deterministically without sleeping in tests.
    function microtime($asFloat = false)
    {
        if (!empty($GLOBALS['telemetry_test_clock'])) {
            return array_shift($GLOBALS['telemetry_test_clock']);
        }

        return \microtime($asFloat);
    }
}

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1 {
    // The package test container does not include Foundation HTTP helpers.
    function abort_unless($condition, $code, $message = '', array $headers = [])
    {
        if (!$condition) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
        }
    }
}

namespace Fleetbase\Support {
    function app($abstract = null, array $parameters = [])
    {
        if ($abstract !== null) {
            return \app($abstract, $parameters);
        }

        return new class() {
            public function environment($environments = null)
            {
                $environment = \config('app.env', 'production');

                return $environments === null ? $environment : in_array($environment, (array) $environments, true);
            }

            public function __call($method, $arguments)
            {
                return \app()->{$method}(...$arguments);
            }
        };
    }

    function url($path, $parameters = [], $secure = null)
    {
        $generator = new \Illuminate\Routing\UrlGenerator(new \Illuminate\Routing\RouteCollection(), \Illuminate\Http\Request::create('https://api.example.test'));

        return $generator->to($path, $parameters, $secure);
    }
}
