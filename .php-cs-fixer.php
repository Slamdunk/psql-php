<?php

declare(strict_types=1);

$config = (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@DoctrineAnnotation' => true,
        '@PHP8x0Migration' => true,
        '@PHP8x0Migration:risky' => true,
        '@PHPUnit8x4Migration:risky' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        'error_suppression' => false,
        'php_unit_test_case_static_method_calls' => false,
        'php_unit_test_class_requires_covers' => false,
    ])
;

$config->getFinder()
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
;

return $config;
