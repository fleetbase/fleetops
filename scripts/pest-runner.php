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
if (!file_exists($vendor) && is_dir($serverVendor) && function_exists('symlink')) {
    @symlink($serverVendor, $vendor);
}

$bootstrap = getcwd() . '/scripts/pest-bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Unable to find Pest bootstrap at scripts/pest-bootstrap.php.\n");
    exit(1);
}

$command = array_merge([
    PHP_BINARY,
    '-d',
    'auto_prepend_file=' . $bootstrap,
    $pest,
], array_slice($argv, 1));

passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);

exit($exitCode);
