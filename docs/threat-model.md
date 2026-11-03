# What each check proves, and what it does not

This library gives you two checks. Neither of them means "you have been paid."
Knowing exactly what each one establishes is the difference between a working
paywall and one that can be drained, so this page is written as a list of
claims you may and may not make.

## The two checks

### `verifyEntry($entry, $expected, $currentLedger, $passphrase)`

Reads a signed `SorobanAuthorizationEntry` and returns normally only if every
check passes, in this order:

| # | Check | Exception |
|---|---|---|
| 1 | The entry has not expired at `$currentLedger` | `ExpiredEntryException` |
| 2 | It does not stay valid past `$expected->validUntilLedger` | `ExpirationExceedsAllowanceException` |
| 3 | It authorizes exactly one SEP-41 `transfer` | `UnexpectedInvocationShapeException` |
| 4 | The asset contract matches | `AssetMismatchException` |
| 5 | The authorizing address is the expected payer | `PayerMismatchException` |
| 6 | The amount matches exactly | `AmountMismatchException` |
| 7 | The recipient matches | `RecipientMismatchException` |
| 8 | A signature over the entry verifies for `$passphrase` | `InvalidSignatureException` |

**You may conclude:** the holder of the payer's key authorized a transfer of
this amount, of this asset, to this recipient, on this network, and that
authorization has not yet expired.

**You may not conclude:**

- **that you have been paid.** Nothing has moved. See *Verification is not
  settlement* below.
- **that this entry is fresh.** It carries no "seen before" marker that the
  library tracks. The same bytes verify every time until they are submitted
  on-chain. Replay protection is yours.
- **that submitting it moves only this charge.** The entry is one
  authorization inside a transaction you have not inspected. See the second
  check.
- **that a delegated entry satisfies the account's policy.** Verification
  proves at least one delegate signature is genuine. How many signers that
  account contract requires is enforced on-chain, not here.

### `verifyTransferEffects($events, $expected)`

Reads the base64 `DiagnosticEvent` XDR from a simulation and returns normally
only if the events describe **exactly one** balance movement: the transfer in
`$expected`.

Anything else fails: a second transfer, a transfer to another party, a
different amount, a mint, a burn, a clawback, or a balance event from a
contract other than the expected asset. An empty event list fails too — no
events means the expected transfer is absent.

**You may conclude:** if this transaction is submitted and its execution
matches the simulation, the only balance change is the charge you agreed to.

**You may not conclude:**

- **that execution will match the simulation.** Ledger state moves between
  simulating and submitting. Simulate close to submission, and treat the
  on-chain result as the final word.
- **that the transaction does nothing else.** The check covers *balance*
  events. A contract in the call tree can still write its own storage or emit
  other events.
- **that fees are what you expect.** Fee accounting is not a balance event of
  the asset contract and is not checked here.

## The gap between the two

`verifyEntry` reads the entry; it cannot see the transaction. A transaction
carrying your verified entry can also carry other operations and invoke other
contracts, and any contract in the call tree can move balances.

This matters most when **you are the party paying the fee or submitting**. An
x402 or MPP facilitator builds the transaction, so it is the facilitator that
must simulate and check the effects. A payee that merely receives an entry and
hands it to someone else to submit gains less from the check — but also has no
idea what the submitter will wrap it in.

The rule: **if you build it, fee-sponsor it, or submit it, simulate it and
check the effects first.** See [facilitator.md](facilitator.md).

## Verification is not settlement

The single most common way to lose money with a library like this one.

A verified entry is a *promise*, enforceable only by submitting it. The money
moves when the transaction lands on-chain, which also consumes the entry's
nonce and makes it unusable again. Until that moment:

- the same entry keeps verifying, every time, for as long as it is unexpired;
- the payer's balance may be spent elsewhere, so submission can still fail;
- the entry can expire while you sit on it.

What you must do:

1. **Record and refuse what you have already accepted.** Key off the entry's
   XDR, or its nonce. The library holds no state and cannot do this for you.
   `examples/laravel-middleware.php` shows one way.
2. **Size that record to outlive the entry.** If you accept entries valid for
   five more minutes of ledgers, your replay record must last longer than
   five minutes, or an entry becomes re-usable the moment you forget it.
3. **Settle promptly**, and treat on-chain confirmation — not verification —
   as the point where the service is paid for.

## The expiry allowance, and why it is not the current ledger

`$expected->validUntilLedger` is **the latest expiry you are willing to
accept**, not the current ledger. If you set it to the current ledger, every
genuine entry is rejected with `ExpirationExceedsAllowanceException`, because
any usable entry is valid for longer than that.

Set it to the current ledger plus the window you are prepared to hold the
charge open for — a few minutes of ledgers is typical. The trade-off:

- **too short** — legitimate payers fail, because their entry was built
  against a ledger a moment behind yours;
- **too long** — you must keep replay records for longer, and the payer's
  authorization stays spendable longer than they probably expected.

## Things this library will never do for you

- **Hold a key.** You pass a callback. The preimage goes in, 64 bytes come
  out, and where the key lives is your system's problem.
- **Decide what network you are on.** The passphrase is a required argument
  everywhere it matters, with no default, because a signature is bound to the
  passphrase it was made for. An entry signed for testnet is refused on
  pubnet — but only if you pass the right passphrase.
- **Talk to the network.** No RPC, no HTTP, no submission. `$currentLedger`
  and the event list come from your own RPC client.
- **Remember anything.** No state, no cache, no nonce tracking.

## Trusting the code itself

- Every byte of XDR and every signature check comes from
  [`sorocharge-core`](https://github.com/sorocharge/sorocharge-core), pinned
  to an exact tag. There is no second signing implementation in PHP — which is
  the whole reason this repo is a native extension rather than a PHP library.
- The core diffs its XDR byte-for-byte against fixtures generated by the
  pinned `@stellar/stellar-sdk`. Those golden vectors are copied into
  `tests/fixtures/` and this binding is tested against the same bytes.
- A Rust panic cannot reach the Zend engine: every exported function wraps its
  body in `guard()`, which converts a panic into `InternalErrorException`. A
  test triggers a real panic and asserts the exception, so this is checked
  rather than assumed.
- The signing callback never runs inside the core's stack frames. Pass one
  captures the exact preimage, your closure runs outside the core, and pass
  two attaches the signature and fails closed if the preimage differs between
  passes.
- Release binaries are built in CI from a tagged commit, verified to load and
  expose the expected API, verified to contain no test hooks, run against the
  suite, and published with `SHA256SUMS`.

To report something this page gets wrong, see [SECURITY.md](../SECURITY.md).
