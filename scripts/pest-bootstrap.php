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

if (!trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
    eval('namespace Illuminate\Foundation\Auth\Access; trait AuthorizesRequests {}');
}

if (!trait_exists('Illuminate\Foundation\Bus\DispatchesJobs')) {
    eval('namespace Illuminate\Foundation\Bus; trait DispatchesJobs {}');
}

if (!trait_exists('Illuminate\Foundation\Validation\ValidatesRequests')) {
    eval('namespace Illuminate\Foundation\Validation; trait ValidatesRequests {}');
}

set_error_handler(function (int $severity, string $message): bool {
    if (str_contains($message, '/pestphp/pest/vendor/autoload.php')) {
        return true;
    }

    return false;
}, E_WARNING);
