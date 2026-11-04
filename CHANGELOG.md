# Changelog

Notable changes to `sorocharge-php`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
uses [semantic versioning](https://semver.org/). Before `1.0.0`, a minor
bump may break the API.

## [Unreleased]

### Added

- `Sorocharge::verifyTransferEffects(array $events, ChargeParams $expected)`,
  binding the core's `verify_transfer_effects`. `verifyEntry` proves the payer
  authorized a charge; it cannot prove that submitting the transaction moves
  only that charge. This reads the diagnostic events from a simulation and
  rejects any balance movement that is not the expected transfer — an extra
  mint, burn, clawback, a second transfer, or a balance event from another
  contract. Callers who build, fee-sponsor or submit transactions should run
  it before submitting.

  This also makes `SimulationEventsMalformedException`,
  `UnexpectedBalanceChangeException` and `ExpectedTransferMissingException`
  reachable. All three shipped in 0.1.0 but nothing could throw them.
- Prebuilt extension binaries. `.github/workflows/release.yml` builds for PHP
  8.1–8.5 across `linux-x86_64`, `linux-aarch64`, `macos-x86_64` and
  `macos-aarch64` and drafts a release with `SHA256SUMS`. Each binary must
  load, expose all four native functions, contain no test hooks, and pass the
  suite before it is uploaded.
- `scripts/install.sh`: checks prerequisites before building, verifies the
  built extension loads in the PHP on `PATH`, and prints the exact
  `extension=` line and drop-in ini directory.
- `CONTRIBUTING.md`, `SECURITY.md` and `CODE_OF_CONDUCT.md`, plus issue
  forms, a pull-request checklist and Dependabot for Cargo and Actions.
- `docs/`: a facilitator guide covering simulate-then-submit, and a threat
  model describing what each check does and does not prove.
- CI now also builds and tests on macOS, which the README has always claimed
  as a supported platform.
- Static analysis of the PHP layer with PHPStan at its strictest level,
  against the extension's own stubs.

### Changed

- The install instructions no longer tell you to run
  `composer require sorocharge/sorocharge`. That has never worked: the package
  is not on Packagist. The README now gives the VCS-repository recipe, which
  does, and says that publication is still pending.
- Tests that call `Sorocharge\Internal\*` carry
  `#[Group('needs-test-hooks')]`, so the release pipeline can run the suite
  against a hook-free binary. `tests/bootstrap.php` takes
  `SOROCHARGE_RELEASE_BINARY=1` to expect their absence, and fails if the
  hooks are present anyway.

## [0.1.0] — 2026-10-20

First release: the signer, bound for PHP.

### Added

- `Sorocharge\ChargeParams`, immutable and validated, with amounts as
  canonical non-negative integer strings — never PHP `int`, which loses
  precision above `PHP_INT_MAX`.
- `Sorocharge::buildChargeEntry()` for all three CAP-71 credential shapes:
  `legacy`, `v2` (`AddressV2`) and `delegated`.
- `Sorocharge::signEntry()`, taking a PHP closure as the signer so the private
  key never passes through this library. Signing runs in two passes so the
  closure is never called from inside the core's stack frames, and fails
  closed if the preimage differs between them.
- `Sorocharge::verifyEntry()`, throwing the exception for the first failing
  check in the core's order.
- `UnsignedEntry::toXdr()`, `SignedEntry::toXdr()`, `SignedEntry::fromXdr()`.
- One exception class per `SorochargeError` variant, all under the abstract
  `Sorocharge\SorochargeException`. The mapping is exhaustive with no wildcard
  arm, so a new core variant fails to compile until it is mapped. Input this
  layer rejects before the core sees it throws SPL's
  `\InvalidArgumentException` instead.
- A Rust panic surfaces as `InternalErrorException` rather than crashing PHP,
  proven by a test that deliberately triggers one.
- Hand-written IDE stubs, kept honest by a reflection test that compares them
  against the loaded extension.
- Runnable examples: a plain Composer script and a Laravel middleware.
- CI across PHP 8.1–8.5 with `fmt`, `clippy -D warnings`, and the suite run
  against the real compiled extension, plus a malware and supply-chain scan.

[Unreleased]: https://github.com/sorocharge/sorocharge-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sorocharge/sorocharge-php/releases/tag/v0.1.0
