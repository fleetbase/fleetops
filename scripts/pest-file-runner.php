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

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

$runner = getcwd() . '/scripts/pest-runner.php';
if (!is_file($runner)) {
    fwrite(STDERR, "Unable to find Pest runner at scripts/pest-runner.php.\n");
    exit(1);
}

$testsPath = getcwd() . '/server/tests';
$files     = glob($testsPath . '/*.php') ?: [];
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
    fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . "\n");

    $process = new Process($command, getcwd());
    $process->setTimeout($timeout);
    $process->setIdleTimeout($timeout);

    try {
        $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    } catch (ProcessTimedOutException $exception) {
        fwrite(STDERR, "\nTimed out after {$timeout} seconds while running {$relativeFile}.\n");
        fwrite(STDOUT, "::endgroup::\n");
        exit(1);
    }

    fwrite(STDOUT, "::endgroup::\n");

    if (!$process->isSuccessful()) {
        fwrite(STDERR, "\nPest failed for {$relativeFile} with exit code {$process->getExitCode()}.\n");
        fwrite(STDOUT, $process->getOutput());
        fwrite(STDERR, $process->getErrorOutput());
        exit($process->getExitCode() ?: 1);
    }
}
