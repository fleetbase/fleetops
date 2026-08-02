<?php

declare(strict_types=1);

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

$runner = getcwd() . '/scripts/pest-runner.php';
if (!is_file($runner)) {
    fwrite(STDERR, "Unable to find Pest runner at scripts/pest-runner.php.\n");
    exit(1);
}

$testsPath = getcwd() . '/server/tests';
$files     = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testsPath, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
        $files[] = $fileInfo->getPathname();
    }
}

$files = array_values(array_unique($files));
sort($files);

if ($files === []) {
    fwrite(STDERR, "Unable to find Pest test files in server/tests.\n");
    exit(1);
}

$args    = array_slice($argv, 1);
$timeout = (float) (getenv('PEST_FILE_TIMEOUT') ?: 120);

foreach ($files as $file) {
    $relativeFile = str_replace(getcwd() . '/', '', $file);

    fwrite(STDOUT, "::group::{$relativeFile}\n");

    $command = array_merge([PHP_BINARY, $runner], $args, [$file]);
    $timeoutBinary = trim((string) shell_exec('command -v timeout'));
    if ($timeoutBinary !== '') {
        $command = array_merge([$timeoutBinary, "{$timeout}s"], $command);
    }

    fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . "\n");

    passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);

    if ($exitCode === 124) {
        fwrite(STDERR, "\nTimed out after {$timeout} seconds while running {$relativeFile}.\n");
        fwrite(STDOUT, "::endgroup::\n");
        exit(1);
    }

    fwrite(STDOUT, "::endgroup::\n");

    if ($exitCode !== 0) {
        if (!in_array('--debug', $args, true)) {
            fwrite(STDOUT, "::group::{$relativeFile} debug\n");
            $debugCommand = array_merge([PHP_BINARY, $runner], $args, ['--debug', $file]);
            if ($timeoutBinary !== '') {
                $debugCommand = array_merge([$timeoutBinary, "{$timeout}s"], $debugCommand);
            }

            fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $debugCommand)) . "\n");
            passthru(implode(' ', array_map('escapeshellarg', $debugCommand)));
            fwrite(STDOUT, "::endgroup::\n");
        }

        fwrite(STDERR, "\nPest failed for {$relativeFile} with exit code {$exitCode}.\n");
        exit($exitCode);
    }
}
