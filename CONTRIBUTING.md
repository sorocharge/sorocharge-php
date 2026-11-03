# Contributing to sorocharge-php

Thanks for looking. This repository is small on purpose, and that shapes what
a useful contribution looks like, so please read the first section before
starting work — it will save you from writing something that has to be
rejected on principle rather than on quality.

## What belongs here, and what does not

This is a **binding layer**. It converts PHP types to Rust types, calls
[`sorocharge-core`](https://github.com/sorocharge/sorocharge-core), and turns
failures into PHP exceptions. Every byte of XDR and every signature check
comes from the core.

**Please do not send:**

- **Signing, verification or XDR logic written in PHP or in this repo's
  Rust.** Two implementations of a payment signer is exactly the problem this
  repo exists to avoid. If the behaviour you want is missing, it belongs in
  `sorocharge-core`; open an issue there, and we will bind it here once it
  lands in a tagged release.
- **A Laravel, Symfony or WordPress package.** The Composer package is
  framework-agnostic. Framework integrations go in `examples/` as runnable
  scripts, never in `php/src/`.
- **An HTTP client or server**, or x402/MPP wire-format handling. This repo
  exposes primitives; callers wire them into their own 402 handling.
- **Key management.** Callers pass a signing callback. This library never
  sees, stores or loads a private key, and will not grow an HSM or KMS
  integration.
- **A new public signature for an existing function.** The four entry points
  mirror the core's Rust API, which this repo does not own.

Good contributions: accuracy and ergonomics at the boundary, tests that cover
a path nothing reaches yet, documentation that is wrong or missing, build and
packaging work, and binding a core function that is already released but not
yet exposed.

## Getting set up

You need PHP 8.1+ with `php-config` and the development headers, Rust 1.98.1+,
libclang, and `ext-sodium` for the tests. The install script checks all of it
and tells you what is missing:

```sh
git clone https://github.com/sorocharge/sorocharge-php
cd sorocharge-php
./scripts/install.sh            # builds, verifies it loads, prints the ini line
composer install --ignore-platform-req=ext-sorocharge
composer test                   # rebuilds with test hooks, runs PHPUnit against it
```

`composer test` builds its own copy with the `test-hooks` feature and loads it
with `-d extension=`, so you do **not** need the extension in your `php.ini`
to run the suite. If one is already there, the script refuses to run rather
than silently testing the wrong binary — PHP keeps the first module of a given
name and only warns. Remove the `extension=` line while working on this repo.

Pass arguments through to PHPUnit after `--`:

```sh
composer test -- --filter VerifyTransferEffects
```

## What CI requires

Every pull request must pass, on PHP 8.1, 8.2, 8.3, 8.4 and 8.5:

| Check | Command |
|---|---|
| Formatting | `cargo fmt --all -- --check` |
| Lints, warnings are errors | `cargo clippy --all-targets --all-features -- -D warnings` |
| Release build | `cargo build --release` |
| Test suite | `composer test` |
| Malware / supply-chain scan | `python3 scripts/malware_scan.py` plus ClamAV |

Run the first four locally before pushing; they are much faster than a CI
round trip.

## Tests are not optional

The suite runs against the **real compiled extension**, never a mock or a
stub. A change to the binding layer is not finished until it has tests, and
`tests/bootstrap.php` refuses to start if the extension is missing or was
built without the test hooks, so a missing extension fails loudly instead of
skipping.

Specifically:

- Every native function needs at least a happy path **and** a thrown-exception
  path, exercised through the compiled extension.
- A new `SorochargeError` variant from the core must be mapped explicitly in
  `src/errors.rs`. The match has no wildcard arm, so an unmapped variant fails
  to compile — do not add a catch-all to get it building. Add the variant to
  `coreErrorVariants()` in `tests/integration/ExceptionHierarchyTest.php` too.
- If you add, remove or rename anything the extension exposes, update
  `php/stubs/sorocharge.stub.php` in the same commit.
  `tests/integration/StubsTest.php` compares the stub against the loaded
  extension by reflection and fails on any difference in class, parent,
  finality, method or parameter name.
- Tests that call `Sorocharge\Internal\*` must carry
  `#[Group('needs-test-hooks')]`, because the release pipeline runs the suite
  against a binary built without those hooks and excludes that group.

## Rules the binding layer has to keep

These are not style preferences; each one exists because breaking it is a
crash or a wrong payment:

- **No Rust panic may reach the Zend engine.** Every exported function wraps
  its body in `guard()`, which converts a panic into
  `InternalErrorException`. An unwind into PHP's C runtime is undefined
  behaviour, not a failed request.
- **No PHP `int` for a Stellar amount, anywhere.** Base-unit amounts exceed
  `PHP_INT_MAX`; amounts are strings, validated as canonical non-negative
  integers. `"010"`, `" 10"`, `"+10"`, `"1e3"` and `"10.0"` are rejected, not
  normalised, so a typo cannot become a different amount.
- **No global or static mutable state**, in the Rust or the PHP layer. Every
  call takes everything it needs, including the network passphrase. There is
  no ambient "current network": a signature is bound to a passphrase, and
  defaulting one would mean signing for a network the caller did not name.
- **Exceptions, never return codes or silent nulls.** Each core error variant
  gets its own class so callers can `catch` as precisely as a Rust caller can
  `match`.
- **Do not call back into PHP from inside the core's stack frames.** See the
  two-pass arrangement in `src/signer.rs`: pass one captures the exact
  preimage, the PHP closure runs outside the core, and pass two attaches the
  signature and fails closed if the preimage changed.

## Moving the core pin

`Cargo.toml` pins `sorocharge-signer` to an exact tag, never a branch or a
bare commit. When it moves:

1. Change the tag and nothing else in that dependency.
2. Re-copy the golden vectors from the new tag's
   `tests/golden_vectors/fixtures/` into `tests/fixtures/`. Copy them; never
   hand-edit a fixture.
3. Map any new error variant, and add it to the hierarchy test.
4. Note the version change in `CHANGELOG.md`.

## Commits and pull requests

- [Conventional commits](https://www.conventionalcommits.org/):
  `type(scope): description`. Types in use here: `feat`, `fix`, `docs`,
  `build`, `ci`, `refactor`, `test`, `chore`.
- One logical change per commit. Don't bulk-stage unrelated work.
- Write the message for someone reading `git log` in a year: say what was
  wrong and why this fixes it, not just what you touched.
- Never force-push a branch someone may have reviewed, and never rewrite
  pushed history on `main`.
- **Never commit a secret, a seed phrase, or a funded key.** The fixtures use
  deliberately unfunded keys derived from fixed single-byte seeds. If you need
  a key for a local example, keep it in a gitignored `.env`.
- Where the behaviour is ambiguous, choose the reading that is more
  conservative about money, and say so in the commit message.

Open a draft pull request early if you want direction on an approach. For
anything beyond a typo, an issue first is usually faster than a surprise
pull request.

## Reporting a vulnerability

Do not open a public issue. See [SECURITY.md](SECURITY.md).

## Licence

By contributing you agree your work is licensed under
[Apache-2.0](LICENSE), the same as the project.
