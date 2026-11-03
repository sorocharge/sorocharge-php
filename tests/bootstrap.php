<?php

declare(strict_types=1);

// The suite runs against the real compiled extension, never a mock. Refuse to
// start if it isn't loaded, or was built without the test hooks the panic and
// error-mapping tests depend on, rather than letting those tests skip quietly.
if (!extension_loaded('sorocharge')) {
    fwrite(STDERR, "The sorocharge extension is not loaded. Run `composer test`, which builds and loads it.\n");
    exit(1);
}

// The one exception is the release pipeline, which runs this suite against the
// binary it is about to publish. That binary must not contain the hooks, so
// the tests needing them are excluded there by group
// (--exclude-group needs-test-hooks) and this check is opted out of
// explicitly. Setting the variable without excluding the group fails loudly in
// those tests, which is the intent: it must not become a quiet skip.
$releaseBinary = getenv('SOROCHARGE_RELEASE_BINARY') === '1';

if (!$releaseBinary && !function_exists('Sorocharge\\Internal\\trigger_panic')) {
    fwrite(STDERR, "The sorocharge extension was built without the `test-hooks` feature. Run `composer test`.\n");
    exit(1);
}
if ($releaseBinary && function_exists('Sorocharge\\Internal\\trigger_panic')) {
    fwrite(STDERR, "SOROCHARGE_RELEASE_BINARY=1 but this build exposes the test hooks; it must not be released.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
