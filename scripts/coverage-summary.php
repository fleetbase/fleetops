<?php

declare(strict_types=1);

$cloverPath = $argv[1] ?? 'coverage/clover.xml';

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Coverage file not found: {$cloverPath}\n");
    fwrite(STDERR, "Run `composer test:coverage:clover` first.\n");
    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if (!$xml) {
    fwrite(STDERR, "Unable to parse Clover coverage file: {$cloverPath}\n");
    exit(1);
}

function coveragePercent(int $covered, int $total): float
{
    return $total === 0 ? 0.0 : round(($covered / $total) * 100, 2);
}

function intMetric(SimpleXMLElement $node, string $name): int
{
    return (int) ($node->metrics[$name] ?? 0);
}

$project = $xml->project;
$metrics = $project->metrics;

$statements        = (int) ($metrics['statements'] ?? 0);
$coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
$methods           = (int) ($metrics['methods'] ?? 0);
$coveredMethods    = (int) ($metrics['coveredmethods'] ?? 0);
$classes           = (int) ($metrics['classes'] ?? 0);
$coveredClasses    = (int) ($metrics['coveredclasses'] ?? 0);

$files       = [];
$directories = [];

foreach ($project->xpath('.//file') ?: [] as $file) {
    $path              = (string) $file['name'];
    $fileStatements    = intMetric($file, 'statements');
    $coveredFileLines  = intMetric($file, 'coveredstatements');
    $fileMethods       = intMetric($file, 'methods');
    $coveredFileMethod = intMetric($file, 'coveredmethods');

    if ($fileStatements === 0) {
        continue;
    }

    $files[] = [
        'path'            => $path,
        'covered'         => $coveredFileLines,
        'statements'      => $fileStatements,
        'methods'         => $fileMethods,
        'covered_methods' => $coveredFileMethod,
        'percent'         => coveragePercent($coveredFileLines, $fileStatements),
    ];

    $relativePath = preg_replace('#^' . preg_quote(getcwd(), '#') . '/?#', '', $path);
    $parts        = explode('/', $relativePath ?: $path);
    $directory    = count($parts) > 2 ? $parts[0] . '/' . $parts[1] : dirname($relativePath ?: $path);

    if (!isset($directories[$directory])) {
        $directories[$directory] = [
            'covered'    => 0,
            'statements' => 0,
        ];
    }

    $directories[$directory]['covered'] += $coveredFileLines;
    $directories[$directory]['statements'] += $fileStatements;
}

usort($files, function (array $a, array $b): int {
    return $a['percent'] <=> $b['percent']
        ?: $b['statements'] <=> $a['statements'];
});

$directoryRows = [];
foreach ($directories as $directory => $directoryMetrics) {
    $directoryRows[] = [
        'directory'  => $directory,
        'covered'    => $directoryMetrics['covered'],
        'statements' => $directoryMetrics['statements'],
        'percent'    => coveragePercent($directoryMetrics['covered'], $directoryMetrics['statements']),
    ];
}

usort($directoryRows, function (array $a, array $b): int {
    return $a['percent'] <=> $b['percent']
        ?: $b['statements'] <=> $a['statements'];
});

printf("Line coverage: %.2f%% (%d/%d statements)\n", coveragePercent($coveredStatements, $statements), $coveredStatements, $statements);
printf("Method coverage: %.2f%% (%d/%d methods)\n", coveragePercent($coveredMethods, $methods), $coveredMethods, $methods);
printf("Class coverage: %.2f%% (%d/%d classes)\n", coveragePercent($coveredClasses, $classes), $coveredClasses, $classes);

echo "\nLowest covered directories:\n";
foreach (array_slice($directoryRows, 0, 10) as $row) {
    printf("  %6.2f%%  %5d/%-5d  %s\n", $row['percent'], $row['covered'], $row['statements'], $row['directory']);
}

echo "\nLowest covered files:\n";
foreach (array_slice($files, 0, 20) as $file) {
    $relativePath = preg_replace('#^' . preg_quote(getcwd(), '#') . '/?#', '', $file['path']);
    printf("  %6.2f%%  %5d/%-5d  %s\n", $file['percent'], $file['covered'], $file['statements'], $relativePath ?: $file['path']);
}
