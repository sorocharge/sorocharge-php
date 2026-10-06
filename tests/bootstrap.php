<?php

declare(strict_types=1);

// The suite runs against the real compiled extension, never a mock. Refuse to
// start if it isn't loaded, or was built without the test hooks the panic and
// error-mapping tests depend on, rather than letting those tests skip quietly.
if (!extension_loaded('sorocharge')) {
    fwrite(STDERR, "The sorocharge extension is not loaded. Run `composer test`, which builds and loads it.\n");
    exit(1);
}
if (!function_exists('Sorocharge\\Internal\\trigger_panic')) {
    fwrite(STDERR, "The sorocharge extension was built without the `test-hooks` feature. Run `composer test`.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
