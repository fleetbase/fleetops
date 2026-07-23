<?php

function writeCoverageFixture(string $path, int $coveredStatements = 2, int $statements = 4): void
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<coverage>
  <project>
    <file name="/tmp/Foo.php">
      <metrics statements="{$statements}" coveredstatements="{$coveredStatements}" methods="2" coveredmethods="1"/>
    </file>
    <metrics statements="{$statements}" coveredstatements="{$coveredStatements}" methods="2" coveredmethods="1" classes="1" coveredclasses="0"/>
  </project>
</coverage>
XML;

    file_put_contents($path, $xml);
}

function coverageSummaryPhpCommand(): string
{
    $binary = escapeshellarg(PHP_BINARY);

    return PHP_SAPI === 'phpdbg' ? $binary . ' -qrr' : $binary;
}

test('coverage summary reports line method class directory and file coverage', function () {
    $fixture = tempnam(sys_get_temp_dir(), 'fleetops-clover-');
    writeCoverageFixture($fixture);

    exec(coverageSummaryPhpCommand() . ' scripts/coverage-summary.php ' . escapeshellarg($fixture), $output, $exitCode);

    @unlink($fixture);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toContain('Line coverage: 50.00% (2/4 statements)')
        ->and(implode("\n", $output))->toContain('Method coverage: 50.00% (1/2 methods)')
        ->and(implode("\n", $output))->toContain('Class coverage: 0.00% (0/1 classes)')
        ->and(implode("\n", $output))->toContain('Lowest covered directories:')
        ->and(implode("\n", $output))->toContain('Lowest covered files:');
});

test('coverage summary fails when coverage is below the configured threshold', function () {
    $fixture = tempnam(sys_get_temp_dir(), 'fleetops-clover-');
    writeCoverageFixture($fixture, 3, 4);

    exec(coverageSummaryPhpCommand() . ' scripts/coverage-summary.php ' . escapeshellarg($fixture) . ' --fail-under=100 2>&1', $output, $exitCode);

    @unlink($fixture);

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $output))->toContain('Coverage 75.00% is below the required 100.00% line threshold.');
});
