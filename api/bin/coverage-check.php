<?php

declare(strict_types=1);

// Fails the build when line coverage in a Clover report is below the threshold.
// Usage: php bin/coverage-check.php coverage/clover.xml 80

$file = $argv[1] ?? 'coverage/clover.xml';
$threshold = (float) ($argv[2] ?? 80);

if (!is_file($file)) {
    fwrite(STDERR, "Coverage report not found: {$file}\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Could not parse coverage report: {$file}\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $total === 0 ? 100.0 : $covered / $total * 100;

printf("Line coverage: %.2f%% (threshold %.0f%%)\n", $percent, $threshold);
exit($percent >= $threshold ? 0 : 1);
