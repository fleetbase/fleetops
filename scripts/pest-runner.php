<?php

declare(strict_types=1);

$pestCandidates = [
    getcwd() . '/server_vendor/bin/pest',
    getcwd() . '/vendor/bin/pest',
    getcwd() . '/server_vendor/pestphp/pest/bin/pest',
    getcwd() . '/vendor/pestphp/pest/bin/pest',
];

$pest = null;
foreach ($pestCandidates as $candidate) {
    if (is_file($candidate)) {
        $pest = $candidate;
        break;
    }
}

if ($pest === null) {
    fwrite(STDERR, "Unable to find Pest. Run composer install first.\n");
    exit(1);
}

$serverVendor = getcwd() . '/server_vendor';
$vendor       = getcwd() . '/vendor';

// Pest hardcodes its autoloader at ../../../vendor/autoload.php (pestphp/pest#920),
// so it needs a `vendor` entry even though this package installs to server_vendor.
// Create the symlink only for the duration of this run and remove it afterwards, so it
// never persists to collide with other tooling — notably the console's Ember build,
// whose addon `vendor/` convention breaks when a dev-linked package exposes this PHP
// server_vendor symlink there.
$createdVendorSymlink = false;
if (!file_exists($vendor) && is_dir($serverVendor) && function_exists('symlink')) {
    $createdVendorSymlink = @symlink($serverVendor, $vendor);
}

if ($createdVendorSymlink) {
    register_shutdown_function(static function () use ($vendor): void {
        if (is_link($vendor)) {
            @unlink($vendor);
        }
    });
}

$bootstrap = getcwd() . '/scripts/pest-bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Unable to find Pest bootstrap at scripts/pest-bootstrap.php.\n");
    exit(1);
}

$args             = array_slice($argv, 1);
$hasConfiguration = false;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--configuration')) {
        $hasConfiguration = true;
        break;
    }
}
$configuration = getcwd() . '/phpunit.xml.dist';

if (!$hasConfiguration && is_file($configuration)) {
    array_unshift($args, '--configuration=' . $configuration);
}

/**
 * Resolve the memory limit to run Pest under.
 *
 * Inheriting the ambient php.ini value makes the suite pass or fail on the
 * developer's local configuration: fleetbase/countries loads a sizeable JSON5
 * dataset, which alone pushes some test files past a stock 128M. Apply a floor
 * so a run is reproducible everywhere, while never lowering an unlimited or
 * already-larger setting. Mirrors FLEETOPS_COVERAGE_MEMORY_LIMIT in the
 * coverage runners.
 */
function fleetopsResolveMemoryLimit(string $ambient, string $floor): string
{
    $toBytes = static function (string $value): float {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return INF;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    };

    return $toBytes($ambient) >= $toBytes($floor) ? $ambient : $floor;
}

$memoryLimit = fleetopsResolveMemoryLimit(
    (string) ini_get('memory_limit'),
    getenv('FLEETOPS_TEST_MEMORY_LIMIT') ?: '512M'
);

$command = array_merge([
    PHP_BINARY,
    '-d',
    'display_errors=1',
    '-d',
    'error_reporting=8191',
    '-d',
    'memory_limit=' . $memoryLimit,
    '-d',
    'auto_prepend_file=' . $bootstrap,
    $pest,
], $args);

passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);

exit($exitCode);
