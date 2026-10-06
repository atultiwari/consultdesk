<?php

declare(strict_types=1);

// Fails the build when line coverage in a Clover report is below the threshold.
// An optional path fragment limits the check to matching files (e.g. src/Domain/).
// Usage: php bin/coverage-check.php coverage/clover.xml 80 [src/Domain/]

$file = $argv[1] ?? 'coverage/clover.xml';
$threshold = (float) ($argv[2] ?? 80);
$scope = $argv[3] ?? null;

if (!is_file($file)) {
    fwrite(STDERR, "Coverage report not found: {$file}\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Could not parse coverage report: {$file}\n");
    exit(1);
}

$total = 0;
$covered = 0;
foreach ($xml->xpath('//file') ?: [] as $node) {
    if ($scope !== null && !str_contains((string) $node['name'], $scope)) {
        continue;
    }
    $total += (int) $node->metrics['statements'];
    $covered += (int) $node->metrics['coveredstatements'];
}

if ($scope !== null && $total === 0) {
    fwrite(STDERR, "No files in the report match {$scope}\n");
    exit(1);
}

$percent = $total === 0 ? 100.0 : $covered / $total * 100;
printf("Line coverage%s: %.2f%% (threshold %.0f%%)\n", $scope === null ? '' : " for {$scope}", $percent, $threshold);
exit($percent >= $threshold ? 0 : 1);
