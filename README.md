# sorocharge-php

Build, sign, and verify Soroban authorization entries for a single SEP-41
charge on Stellar, from PHP. It is the signing core behind x402 and MPP-charge
payments. It is a native PHP extension wrapping
[`sorocharge-core`](https://github.com/sorocharge/sorocharge-core) (Rust), so
there is no Node.js anywhere in the chain and no second, PHP-side
implementation of the signing logic.

Every byte of XDR and every signature check comes from `sorocharge-core`. This
repo only converts PHP types to Rust types and back, and turns failures into
PHP exceptions. For how entries are built, signed, and verified, and how they
are byte-checked against `@stellar/stellar-sdk`, see the core repo.

## Scope

This binding wraps core's signer crate: `buildChargeEntry`, `signEntry`,
`verifyEntry`, and `verifyTransferEffects`. It does not wrap the core's x402
or MPP protocol crates, does not speak HTTP, and does not submit
transactions. You put the signed entry into your own x402/MPP framing and
settlement. Keys are never stored: you pass a signing callback.

The two checks answer different questions, and a payee that takes money needs
both:

| Question | Call |
|---|---|
| Did the payer authorize exactly this charge? | `verifyEntry` |
| Does submitting it move exactly this charge, and nothing else? | `verifyTransferEffects` |

`verifyEntry` reads the signed entry. It cannot see what the transaction will
actually do: the same invocation can emit a second transfer, a mint, a burn,
or a clawback, and any contract in the call tree is free to do so.
`verifyTransferEffects` reads the diagnostic events from a simulation and
rejects every balance movement that is not the expected transfer. Run it
before you submit, and especially before you sponsor the fees.

## Requirements

- **PHP 8.1 or newer**, on Linux or macOS. The extension is built with
  [ext-php-rs](https://github.com/extphprs/ext-php-rs) 0.16, whose build
  refuses any PHP older than 8.1 (8.0 is end-of-life). `ChargeParams` also
  exposes its fields as PHP 8.1 readonly properties. CI tests 8.1 through 8.5.
- `ext-sodium`, only for the test suite and examples, which sign with libsodium.

Building from source additionally needs:

- **PHP development headers and `php-config`** on `PATH`: ext-php-rs reads the
  PHP build configuration from it. Debian/Ubuntu: `apt install php8.x-dev`.
  Homebrew PHP and [setup-php](https://github.com/shivammathur/setup-php)
  include it.
- **libclang**, used by bindgen to read the PHP headers. macOS: Xcode command
  line tools. Debian/Ubuntu: `apt install libclang-dev`.
- **Rust 1.98.1** or newer (`rustup`).

## Install

Two steps, in this order: the native extension, then the PHP classes.

### 1. The extension

A PHP extension is binary-compatible only with the PHP **minor** version and
platform it was built for. Loading one built for a different minor version
fails with an API-mismatch warning and the extension simply doesn't appear, so
match it to the output of `php --version`.

**From a release.** Download the file for your PHP version and platform from
[Releases](https://github.com/sorocharge/sorocharge-php/releases), check it
against the release's `SHA256SUMS`, and load it:

```sh
sha256sum --check --ignore-missing SHA256SUMS
```

**From source.** The install script checks every prerequisite before
building, verifies that your PHP really loads the result, and prints the exact
`extension=` line and the right place to put it:

```sh
git clone https://github.com/sorocharge/sorocharge-php
cd sorocharge-php
./scripts/install.sh
```

Either way, confirm it with:

```sh
php -m | grep sorocharge
```

### 2. The PHP package

The facade (`Sorocharge\Sorocharge`) and the exception hierarchy are plain
PHP, installed with Composer.

> **Not on Packagist yet.** `composer require sorocharge/sorocharge` does not
> resolve. Until it is published, point Composer at this repository, which
> works today and pins by tag just the same.

In your project's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/sorocharge/sorocharge-php" }
    ],
    "require": {
        "sorocharge/sorocharge": "^0.1"
    }
}
```

Then `composer update sorocharge/sorocharge`. If you have not loaded the
extension yet, add `--ignore-platform-req=ext-sorocharge` — the package
declares it, so Composer refuses to install until the extension is present or
the check is skipped.

## Quickstart

```php
use Sorocharge\ChargeParams;
use Sorocharge\SignedEntry;
use Sorocharge\Sorocharge;
use Sorocharge\SorochargeException;

$charge = new ChargeParams(
    assetContract: 'CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQVU2HHGCYSC', // XLM SAC, testnet
    amount: '5000000',            // base units (stroops), always a string
    payer: $payerAddress,         // G...
    recipient: $recipientAddress, // G... or C...
    validUntilLedger: $currentLedger + 60,
);

// Payer: build and sign. Your callback receives the 32-byte preimage and
// returns the raw 64-byte ed25519 signature, from wherever the key lives.
$unsigned = Sorocharge::buildChargeEntry($charge, 'v2');
$signed = Sorocharge::signEntry(
    $unsigned,
    fn (string $preimage): string => $kms->signEd25519($preimage),
    $payerAddress,
    Sorocharge::TESTNET_PASSPHRASE,
);
$wire = $signed->toXdr(); // base64 SorobanAuthorizationEntry XDR

// Payee: decode what arrived and check it is exactly the expected charge.
try {
    Sorocharge::verifyEntry(SignedEntry::fromXdr($wire), $expectedCharge, $currentLedger, Sorocharge::TESTNET_PASSPHRASE);
} catch (SorochargeException $e) {
    // AmountMismatchException, ExpiredEntryException, InvalidSignatureException, ...
}
```

Full, runnable versions are in [`examples/`](examples/): a plain Composer
script and a Laravel middleware, which both do the round trip against a live
network's ledger, and `verify-transfer-effects.php`, which needs no network or
keys because it replays a real testnet transaction's events.

Before taking real money, read [docs/facilitator.md](docs/facilitator.md) for
the order of operations and [docs/threat-model.md](docs/threat-model.md) for
what a passing check does and does not prove.

## API

| Method | Notes |
|---|---|
| `new ChargeParams(string $assetContract, string $amount, string $payer, string $recipient, int $validUntilLedger)` | Read-only. `$amount` must be a canonical non-negative integer string (no sign, whitespace, or leading zeros; up to i128). `$assetContract` must be a `C...` contract. |
| `Sorocharge::buildChargeEntry(ChargeParams $params, string $credentialKind, array $delegates = []): UnsignedEntry` | `$credentialKind`: `'legacy'`, `'v2'` (CAP-71, mandatory from protocol 28), or `'delegated'`. `$delegates` is required and non-empty for `'delegated'`, and must be empty otherwise. |
| `Sorocharge::signEntry(UnsignedEntry $entry, callable $signPreimage, string $publicAddress, string $networkPassphrase): SignedEntry` | `$publicAddress` is the payer or, for a delegated entry, one delegate. An exception thrown by `$signPreimage` reaches you unchanged. |
| `Sorocharge::verifyEntry(SignedEntry $entry, ChargeParams $expected, int $currentLedger, string $networkPassphrase): void` | Returns only if every check passes, otherwise throws for the first failure, in order: expiry, expiry allowance, invocation shape, asset, payer, amount, recipient, signature. `$expected->validUntilLedger` is the latest expiry you accept: an entry valid any longer is rejected. |
| `Sorocharge::verifyTransferEffects(array $events, ChargeParams $expected): void` | Returns only if the simulation's events describe exactly one balance change: the transfer in `$expected`. `$events` are base64 `DiagnosticEvent` XDR (`diagnosticEventsXdr` from Soroban RPC); non-contract events are ignored, so pass the list through unfiltered. `$expected->validUntilLedger` is not read — an event stream carries no expiry. An empty list throws. |
| `UnsignedEntry::toXdr()`, `SignedEntry::toXdr()`, `SignedEntry::fromXdr(string)` | Base64 `SorobanAuthorizationEntry` XDR. `fromXdr` proves nothing; only `verifyEntry` does. |

The network passphrase is a required argument everywhere it matters. There is
no default network, because a signature is bound to the passphrase it was made
for. `Sorocharge::TESTNET_PASSPHRASE` and `Sorocharge::PUBNET_PASSPHRASE` are
provided.

IDE stubs for the native classes are in
[`php/stubs/sorocharge.stub.php`](php/stubs/sorocharge.stub.php).

### Exceptions

Every `sorocharge-core` error has its own class, all extending the abstract
`Sorocharge\SorochargeException` (which extends `\Exception`):
`InvalidAddressException`, `UnsupportedCredentialTypeException`,
`EmptyDelegateSignersException`, `DuplicateDelegateSignerException`,
`ExpiredEntryException`, `ExpirationExceedsAllowanceException`,
`UnexpectedInvocationShapeException`,
`AssetMismatchException`, `PayerMismatchException`, `AmountMismatchException`,
`RecipientMismatchException`, `InvalidSignatureException`,
`NoMatchingCredentialNodeException`, `SigningFailedException`,
`XdrEncodingFailedException`, and the simulation-check exceptions
`SimulationEventsMalformedException`, `UnexpectedBalanceChangeException`, and
`ExpectedTransferMissingException`. The last three come only from
`verifyTransferEffects`: malformed or undecodable events, a balance movement
that isn't the expected transfer (including the right transfer repeated), and
an event stream with no matching transfer at all.

Input this library rejects before reaching the core (a malformed amount, an
unknown credential kind, delegates where none belong) throws SPL's
`\InvalidArgumentException`. That's a programming error, not a payment-check
failure.

A Rust panic never crosses into PHP: it surfaces as
`Sorocharge\InternalErrorException`. That is always a bug here, so please
report it. A fatal error inside your `$signPreimage` callback stops the script
exactly as it would anywhere else.

## Before you take payments with this

- **Verification is not settlement.** A valid entry proves the payer
  authorized the transfer. The money moves only when the entry is submitted
  on-chain, which also consumes its nonce. Until then the same entry keeps
  verifying, so refuse entries you have already accepted (the Laravel example
  shows one way) and settle promptly.
- **Simulate and check the effects before you submit.** `verifyEntry` alone
  does not tell you what the transaction does. If you build the transaction,
  pay its fee, or submit on someone else's behalf, simulate it and pass
  `diagnosticEventsXdr` to `verifyTransferEffects` with the same
  `ChargeParams`. Without that check, an invocation can carry the charge you
  verified plus balance movements you never agreed to.
- **Set `$expected->validUntilLedger` to the latest expiry you accept**, such
  as the current ledger plus a few minutes of ledgers. Not the current ledger
  itself: then every real entry is rejected as too long-lived. Size your
  replay protection to outlive that window.
- **Delegated entries:** verification proves at least one delegate signature
  is genuine. The account contract's own policy (how many delegates must
  sign) is enforced on-chain, not here.

## Development

```sh
composer install --ignore-platform-req=ext-sorocharge   # the extension isn't built yet
composer test    # builds with test hooks, runs PHPUnit against that build
composer stan    # PHPStan, level max, on the shipped php/src surface
```

The suite runs against the real compiled extension. It includes stellar-sdk
golden vectors shared with `sorocharge-core` (in `tests/fixtures/`), a
deliberately triggered Rust panic, a fatal error inside a signing callback,
and a reflection check that the IDE stubs match the extension. The
`test-hooks` Cargo feature that enables the panic and error-mapping hooks is
never part of a release build.

The examples need `SOROCHARGE_NETWORK` (`testnet` or `pubnet`; never
defaulted) and `SOROCHARGE_TEST_SECRET` (the payer's `S...` seed). Keep both in
your environment or a gitignored `.env`.

## Documentation

- [docs/facilitator.md](docs/facilitator.md) — taking a charge end to end:
  verify, simulate, check the effects, submit, settle
- [docs/threat-model.md](docs/threat-model.md) — what each check proves, and
  what it does not
- [CONTRIBUTING.md](CONTRIBUTING.md) — what belongs in this repo, the CI gates,
  and how to move the core pin
- [SECURITY.md](SECURITY.md) — private reporting, scope, and what is
  deliberate rather than a bug
- [CHANGELOG.md](CHANGELOG.md)

## License

Apache-2.0
