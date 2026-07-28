<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

// Merging hundreds of per-file coverage snapshots into one clover report
// needs more than the default 128M limit
ini_set('memory_limit', '-1');

$autoloadCandidates = [
    getcwd() . '/server_vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];

$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "Unable to find Composer autoload. Run composer install first.\n");
    exit(1);
}

require_once $autoload;

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

$args       = array_slice($argv, 1);
$cloverPath = getcwd() . '/coverage/clover.xml';
$pestArgs   = [];
$targets    = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--coverage-clover=')) {
        $path       = substr($arg, strlen('--coverage-clover='));
        $cloverPath = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        continue;
    }

    if ($arg === '--coverage-clover') {
        fwrite(STDERR, "Use --coverage-clover=path with coverage-file-runner.php.\n");
        exit(1);
    }

    if ($arg !== '' && $arg[0] !== '-') {
        $targets[] = $arg;
        continue;
    }

    if (!str_starts_with($arg, '--coverage-')) {
        $pestArgs[] = $arg;
    }
}

if ($targets === []) {
    $targets[] = 'server/tests';
}

$files = [];
foreach ($targets as $target) {
    $path = getcwd() . '/' . ltrim($target, '/');
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }

    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
    }
}

$files = array_values(array_unique($files));
sort($files);

if ($files === []) {
    fwrite(STDERR, "Unable to find Pest test files for coverage.\n");
    exit(1);
}

$coverageDir = dirname($cloverPath);
if (!is_dir($coverageDir) && !mkdir($coverageDir, 0777, true) && !is_dir($coverageDir)) {
    fwrite(STDERR, "Unable to create coverage directory: {$coverageDir}\n");
    exit(1);
}

$tmpDir = $coverageDir . '/.coverage-file-runner';
if (is_dir($tmpDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $fileInfo) {
        $fileInfo->isDir() ? rmdir($fileInfo->getPathname()) : unlink($fileInfo->getPathname());
    }
} elseif (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Unable to create temporary coverage directory: {$tmpDir}\n");
    exit(1);
}

$timeout       = (float) (getenv('PEST_FILE_TIMEOUT') ?: 120);
$timeoutBinary = trim((string) shell_exec('command -v timeout'));
$memoryLimit   = getenv('FLEETOPS_COVERAGE_MEMORY_LIMIT') ?: '-1';
$coverageFiles = [];

foreach ($files as $index => $file) {
    $relativeFile = str_replace(getcwd() . '/', '', $file);
    $coverageFile = $tmpDir . '/' . str_pad((string) $index, 4, '0', STR_PAD_LEFT) . '.cov';

    fwrite(STDOUT, "::group::{$relativeFile} coverage\n");

    $command = array_merge([
        PHP_BINARY,
        '-d',
        'memory_limit=' . $memoryLimit,
        $pestRunner,
    ], $pestArgs, [
        '--coverage-php=' . $coverageFile,
        $file,
    ]);

    if ($timeoutBinary !== '') {
        $command = array_merge([$timeoutBinary, "{$timeout}s"], $command);
    }

    fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . "\n");
    passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);
    fwrite(STDOUT, "::endgroup::\n");

    if ($exitCode === 124) {
        fwrite(STDERR, "\nTimed out after {$timeout} seconds while running {$relativeFile}.\n");
        exit(1);
    }

    if ($exitCode !== 0) {
        fwrite(STDERR, "\nPest coverage failed for {$relativeFile} with exit code {$exitCode}.\n");
        exit($exitCode);
    }

    $coverageFiles[] = $coverageFile;
}

$merged = null;
foreach ($coverageFiles as $coverageFile) {
    $coverage = include $coverageFile;

    if (!$coverage instanceof CodeCoverage) {
        fwrite(STDERR, "Invalid coverage artifact: {$coverageFile}\n");
        exit(1);
    }

    if ($merged === null) {
        $merged = $coverage;
        continue;
    }

    $merged->merge($coverage);
}

if (!$merged instanceof CodeCoverage) {
    fwrite(STDERR, "No coverage data was produced.\n");
    exit(1);
}

(new Clover())->process($merged, $cloverPath);
fwrite(STDOUT, "Wrote Clover coverage to {$cloverPath}\n");
