#!/usr/bin/env bash
# Builds the sorocharge extension and tells you exactly how to load it.
#
# Checks every prerequisite before doing any work, so a missing dependency is
# reported as a named package to install rather than as a bindgen or linker
# error several minutes in. Never edits php.ini: it prints the line to add,
# because which ini file is the right one depends on how PHP was installed and
# getting it wrong silently loads nothing.
#
# Usage: scripts/install.sh [--debug] [--print-ini-only]
set -euo pipefail
cd "$(dirname "$0")/.."

profile=release
cargo_flags=(--release)
print_only=0
for arg in "$@"; do
    case "$arg" in
        --debug) profile=debug; cargo_flags=() ;;
        --print-ini-only) print_only=1 ;;
        -h | --help) sed -n '2,10p' "$0"; exit 0 ;;
        *) echo "unknown argument: $arg (try --help)" >&2; exit 2 ;;
    esac
done

die() { echo "error: $*" >&2; exit 1; }

case "$(uname -s)" in
    Darwin) lib=libsorocharge.dylib; clang_pkg="the Xcode command line tools (xcode-select --install)" ;;
    Linux)  lib=libsorocharge.so;    clang_pkg="libclang-dev (apt) or clang-devel (dnf)" ;;
    *) die "unsupported platform $(uname -s). This extension builds on Linux and macOS." ;;
esac
lib_path="$PWD/target/$profile/$lib"

if [ "$print_only" -eq 1 ]; then
    [ -f "$lib_path" ] || die "no build at $lib_path. Run scripts/install.sh first."
    echo "extension=$lib_path"
    exit 0
fi

# --- prerequisites, all checked before building ---

command -v php-config >/dev/null 2>&1 || die \
    "php-config is not on PATH. ext-php-rs reads the PHP build configuration from it.
  Debian/Ubuntu: apt install php-dev     (or php8.3-dev for a specific version)
  Fedora/RHEL:   dnf install php-devel
  macOS:         brew install php"

php_version=$(php-config --version)
php_major_minor=${php_version%.*}
if [ "$(printf '%s\n8.1\n' "$php_major_minor" | sort -V | head -1)" != "8.1" ]; then
    die "php-config reports PHP $php_version, but this extension requires 8.1 or newer.
  ext-php-rs refuses anything older, and ChargeParams uses 8.1 readonly properties."
fi

command -v cargo >/dev/null 2>&1 || die \
    "cargo is not on PATH. Install Rust 1.98.1 or newer from https://rustup.rs."

if ! command -v clang >/dev/null 2>&1 && [ -z "${LIBCLANG_PATH:-}" ]; then
    die "clang/libclang not found; bindgen needs it to read the PHP headers.
  Install $clang_pkg, or set LIBCLANG_PATH."
fi

# An extension php.ini already loads would shadow this build: PHP keeps the
# first module of a given name and only warns, so the verify step below would
# pass against the wrong binary. Warn now, while the message can still be read.
preloaded=0
if php -r 'exit(extension_loaded("sorocharge") ? 0 : 1);' 2>/dev/null; then
    preloaded=1
fi

# --- build ---

echo "Building the sorocharge extension ($profile) for PHP $php_version ..."
cargo build "${cargo_flags[@]}"
[ -f "$lib_path" ] || die "the build reported success but $lib_path is missing."

# --- verify the built extension actually loads ---

if [ "$preloaded" -eq 1 ]; then
    echo
    echo "warning: php.ini already loads a sorocharge extension, so this build"
    echo "         could not be verified against it. Remove the existing"
    echo "         extension= line and re-run to check this build."
else
    loaded=$(php -d extension="$lib_path" -r \
        'echo extension_loaded("sorocharge") ? "yes" : "no";' 2>/dev/null || echo "no")
    [ "$loaded" = "yes" ] || die \
        "$lib_path was built but PHP would not load it.
  The usual cause is a PHP version mismatch: an extension is binary-compatible
  only with the PHP minor version it was built against ($php_version here).
  Check that the php on PATH is the same build php-config describes:
    php --version && php-config --version"
    echo "Verified: PHP $php_version loads the extension."
fi

# --- tell the user what to do with it ---

ini_dir=$(php-config --ini-dir 2>/dev/null || true)
ini_file=$(php -r 'echo php_ini_loaded_file() ?: "";' 2>/dev/null || true)

cat <<EOF

Built: $lib_path

To load it on every run, add this line to your php.ini:

    extension=$lib_path

EOF

if [ -n "$ini_dir" ]; then
    echo "Your PHP reads drop-in ini files from $ini_dir, so the tidiest option is:"
    echo
    echo "    echo 'extension=$lib_path' | sudo tee $ini_dir/sorocharge.ini"
elif [ -n "$ini_file" ]; then
    echo "Your loaded php.ini is $ini_file."
else
    echo "This PHP has no loaded php.ini; see 'php --ini' for where to create one."
fi

cat <<EOF

Then confirm:

    php -m | grep sorocharge

Or load it for a single command without editing any ini file:

    php -d extension=$lib_path your-script.php

Next, add the PHP classes (the facade and the exception hierarchy). Until the
package is on Packagist, point Composer at this repository -- see the Install
section of README.md.
EOF
