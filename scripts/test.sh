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

# -n: ignore php.ini, so a globally installed (possibly release) copy of the
# extension can't load first and shadow the one just built.
exec php -n -d extension="$SOROCHARGE_EXTENSION" vendor/bin/phpunit "$@"
