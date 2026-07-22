<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The plugin's own composer.json intentionally does not declare
// ucp-php-sdk as a dependency - in production, Ucp\Sdk\* classes (used by
// X402CheckoutResponseAugmenter and friends) are provided by the platform
// lane's shared vendor (via shopware/agentic-commerce), not by this
// plugin's own vendor/. Load that lane autoloader too, when present, so
// unit tests can construct Ucp\Sdk\* types without duplicating the SDK as
// a standalone dependency here.
$laneAutoload = __DIR__ . '/../../../../vendor/autoload.php';

if (is_file($laneAutoload)) {
    require $laneAutoload;
}
