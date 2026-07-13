<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    // src/Ucp bridges to ucp-php-sdk / SwagAgenticCommerce, which are not
    // dependencies of this plugin: the services load conditionally at runtime
    // only when those packages are installed (SwagX402Payments::build()).
    ->ignoreErrorsOnPath(__DIR__ . '/src/Ucp', [ErrorType::UNKNOWN_CLASS]);

if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

return $config;
