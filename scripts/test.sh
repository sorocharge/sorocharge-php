#!/usr/bin/env bash
# Builds the extension with the test hooks and runs the PHPUnit suite
# against it. Extra arguments are passed through to PHPUnit.
set -euo pipefail
cd "$(dirname "$0")/.."

cargo build --features test-hooks

case "$(uname -s)" in
    Darwin) lib=libsorocharge.dylib ;;
    *) lib=libsorocharge.so ;;
esac
export SOROCHARGE_EXTENSION="$PWD/target/debug/$lib"

# A copy of the extension already loaded by php.ini would shadow the one just
# built (PHP keeps the first and only warns), so refuse rather than test it.
# php.ini itself stays on: PHPUnit needs extensions it loads (dom, mbstring).
if php -r 'exit(extension_loaded("sorocharge") ? 0 : 1);'; then
    echo "php.ini already loads a sorocharge extension; disable it to test this build." >&2
    exit 1
fi

exec php -d extension="$SOROCHARGE_EXTENSION" vendor/bin/phpunit "$@"
