<?php

declare(strict_types=1);

$args = array_slice($argv, 1);

if ($args === []) {
    fwrite(STDERR, "Usage: php scripts/coverage-runner.php <pest coverage arguments>\n");
    exit(1);
}

$pestRunner = getcwd() . '/scripts/pest-runner.php';
if (!is_file($pestRunner)) {
    fwrite(STDERR, "Unable to find Pest runner at scripts/pest-runner.php.\n");
    exit(1);
}

$hasCoverageExtension = extension_loaded('xdebug') || extension_loaded('pcov');

if (!$hasCoverageExtension) {
    fwrite(STDERR, "No PHP coverage driver is available.\n\n");
    fwrite(STDERR, "Install or enable one of:\n");
    fwrite(STDERR, "  - Xdebug with XDEBUG_MODE=coverage\n");
    fwrite(STDERR, "  - PCOV\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, 'Current PHP binary: ' . PHP_BINARY . "\n");
    exit(1);
}

putenv('XDEBUG_MODE=coverage');
$_ENV['XDEBUG_MODE']    = 'coverage';
$_SERVER['XDEBUG_MODE'] = 'coverage';

$command        = array_merge([PHP_BINARY, $pestRunner], $args);
$escapedCommand = implode(' ', array_map('escapeshellarg', $command));

passthru($escapedCommand, $exitCode);

exit($exitCode);
