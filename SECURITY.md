# Security policy

This library authorizes movements of money. Please treat bugs in it as
security bugs by default, and report them privately.

## Reporting a vulnerability

**Do not open a public issue, pull request or discussion for a suspected
vulnerability.**

Use GitHub's private vulnerability reporting:
[**Report a vulnerability**](https://github.com/sorocharge/sorocharge-php/security/advisories/new).
It is visible only to the maintainers, and lets us prepare a fix and an
advisory before anything is public.

Please include, as far as you have it:

- the version or commit, your PHP version, and your platform;
- what an attacker gains — accepting an unauthorized charge, rejecting a valid
  one, leaking key material, crashing the process;
- the smallest reproduction you can manage. A failing PHPUnit test against the
  compiled extension is ideal. Base64 XDR and the `ChargeParams` involved are
  usually enough;
- whether you intend to disclose publicly, and when.

What to expect:

| | |
|---|---|
| First response | within 5 working days |
| Assessment and severity | within 10 working days of the first response |
| Fix, or a plan with dates | agreed with you in the advisory thread |
| Credit | by name or handle, as you prefer; tell us if you want none |

This is a small volunteer-maintained project with no bug bounty. We will not
threaten or pursue anyone who reports in good faith under this policy.

## Supported versions

| Version | Supported |
|---|---|
| `0.1.x` | Yes |
| < `0.1` | No |

Pre-1.0: fixes land on `main` and in the next `0.1.x` tag. There is no
long-term support branch yet.

## What is in scope here

This repository is the PHP binding layer. In scope:

- **Anything crossing the FFI boundary unsafely** — a Rust panic reaching the
  Zend engine, a use-after-free, a bailout jumping over Rust frames that own
  heap values, memory unsafety of any kind.
- **A wrong exception, or no exception**, where a check failed: a
  `verifyEntry` or `verifyTransferEffects` failure that returns normally is
  critical, because a caller reads that as "paid".
- **Precision or parsing bugs in amounts.** An amount that is silently
  truncated, rounded, wrapped, or reinterpreted (`"010"` as 10, a value past
  `PHP_INT_MAX` losing digits) is a security bug, not a formatting one.
- **Address, ledger or passphrase handling** in this layer: a network
  passphrase ignored or defaulted, a ledger bound that wraps, an address
  accepted that is not a valid strkey.
- **Leaking key material** — the signing preimage or the callback's return
  value appearing in an exception message, a log, or a core dump.
- **A release artifact that does not match its source**, or a shipped binary
  containing the `test-hooks` feature.

## What belongs elsewhere

- **Cryptography, XDR construction, preimage derivation, and the verification
  checks themselves** live in
  [`sorocharge-core`](https://github.com/sorocharge/sorocharge-core). Report
  those there. If you are unsure which side a bug is on, report it here and
  we will route it.
- **`ext-php-rs`, `stellar-xdr`, PHP itself, or the Stellar protocol** —
  report upstream. Tell us too, so we can pin or work around it.
- **Your own key storage.** This library never sees a private key: you pass a
  callback. Where that key lives is your system's problem, not this one's.

## Things that are not bugs, by design

Please check these before reporting:

- **`SignedEntry::fromXdr()` accepts anything that decodes.** It proves
  nothing about the signature. Only `verifyEntry` does. Decoding attacker-
  supplied XDR is expected.
- **Verification is not settlement.** A verified entry stays verifiable until
  it is submitted on-chain and its nonce is consumed. Replay protection is
  the caller's: refuse entries you have already accepted, and settle
  promptly. This library holds no state and cannot do it for you.
- **A delegated entry only proves one delegate signature is genuine.** How
  many signers the account contract requires is enforced on-chain, not here.
- **`verifyEntry` does not tell you what a transaction will do.** That is what
  `verifyTransferEffects` is for. If you sponsor fees or submit on someone's
  behalf without simulating and checking the effects, an invocation can carry
  the charge you verified plus balance movements you did not agree to. Not
  running that check is a deployment mistake, and a documented one.
- **`InternalErrorException` is always a bug in this extension.** Please do
  report it — but as a bug, publicly, unless it carries attacker-controlled
  memory contents or key material.

## Verifying what you run

Release binaries come with `SHA256SUMS`:

```sh
sha256sum --check --ignore-missing SHA256SUMS
```

Each is built in CI from a tagged commit, checked to load and expose the
expected API, checked to be free of the `test-hooks` feature, and run against
the test suite before upload. Building from source with
`./scripts/install.sh` is always an option, and is the only one that does not
require trusting our build pipeline.
