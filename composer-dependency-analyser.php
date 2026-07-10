<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

$config = (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false);

if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

return $config;
