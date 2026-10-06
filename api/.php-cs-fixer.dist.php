<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/public', __DIR__ . '/migrations', __DIR__ . '/bin']);

return (new PhpCsFixer\Config())
    // Host PHP may be newer than the 8.1 target; rules are version-safe.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP81Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'final_class' => true,
    ])
    ->setFinder($finder);
