<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    // src/Ucp bridges to ucp-php-sdk / SwagAgenticCommerce, which are not
    // dependencies of this plugin: the services load conditionally at runtime
    // only when those packages are installed (SwagX402Payments::build()).
    ->ignoreErrorsOnPath(__DIR__ . '/src/Ucp', [ErrorType::UNKNOWN_CLASS])
    // The augmenter test exercises that same optional bridge and references
    // Ucp\Sdk\* types; it skips at runtime when the SDK is absent (see
    // tests/bootstrap.php), so the analyser cannot autoload the symbols here.
    ->ignoreErrorsOnPath(__DIR__ . '/tests/Unit/Ucp', [ErrorType::UNKNOWN_CLASS])
    // Provides the #[\Override] attribute class on PHP 8.2 (the minimum
    // supported version). On PHP >= 8.3 the symbol resolves to core, so the
    // analyser would report the package as unused there; on 8.2 it would be a
    // shadow dependency without the require. Keep it and ignore the
    // runtime-dependent verdict. The ignore only matches on >= 8.3 runtimes,
    // so unmatched-ignore reporting must be off for the 8.2 CI runner.
    ->ignoreErrorsOnPackage('symfony/polyfill-php83', [ErrorType::UNUSED_DEPENDENCY])
    ->disableReportingUnmatchedIgnores();

if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

return $config;
