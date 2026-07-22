<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$pluginAutoload = __DIR__ . '/../vendor/autoload.php';

$loader = null;
if (is_file($pluginAutoload)) {
    $loader = require $pluginAutoload;
}

// The plugin's own composer.json intentionally does not declare
// ucp-php-sdk as a dependency - in production, Ucp\Sdk\* classes (used by
// X402CheckoutResponseAugmenter and friends) are provided by the platform
// lane's shared vendor (via shopware/agentic-commerce), not by this
// plugin's own vendor/. Rather than requiring the lane's autoload.php
// (which runs the lane's platform_check.php and aborts below its PHP
// floor - currently >= 8.4.1, well above this plugin's own ^8.2), register
// the SDK's PSR-4 prefixes directly onto the plugin's own ClassLoader.
// This keeps the plugin autoloader authoritative and never touches the
// lane's platform_check, so the suite still runs on the plugin's own PHP
// floor when the lane's is higher.
// Probe the known locations the SDK can live in, most specific first: the
// platform lane's shared vendor (agent-shop monorepo layout), then a locally
// vendored copy should this plugin ever require it directly. The first hit
// wins; when none exist, the SDK-dependent tests skip themselves.
$sdkCandidates = [
    __DIR__ . '/../../../../vendor/ucp-php-sdk',
    __DIR__ . '/../vendor/ucp-php-sdk',
];

if ($loader instanceof ClassLoader && !interface_exists('Ucp\\Sdk\\Contract\\CheckoutResponseAugmenterInterface')) {
    foreach ($sdkCandidates as $sdkDir) {
        if (is_dir($sdkDir)) {
            $loader->addPsr4('Ucp\\Sdk\\', $sdkDir . '/core/src');
            $loader->addPsr4('Ucp\\Sdk\\Symfony\\', $sdkDir . '/symfony-bundle/src');

            break;
        }
    }
}
