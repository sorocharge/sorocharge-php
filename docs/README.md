# sorocharge-php documentation

The [README](../README.md) covers installing the extension and the API surface.
These pages cover using it correctly.

| Page | Read it when |
|---|---|
| [facilitator.md](facilitator.md) | You are building the service that charges. The order of operations, end to end, with the two library calls in their correct places and a table of which errors are worth retrying. |
| [threat-model.md](threat-model.md) | You want to know what a passing check actually entitles you to conclude — and what it does not. Written as claims you may and may not make. |
| [wave-appeal.md](wave-appeal.md) | Maintainer-facing. The Drips Stellar Wave appeal: the documented process, what changed in this repo since the rejection, and the owner's remaining checklist. |

Start with the facilitator guide if you are writing code, and the threat model
before you take real money.

## The short version

1. `verifyEntry` proves the payer authorized this charge. It is not payment,
   and it does not describe what a transaction will do.
2. `verifyTransferEffects` proves a simulated transaction moves only that
   charge. Run it before you submit, and always if you pay the fee.
3. Neither call remembers anything. Replay protection and settlement
   confirmation are yours.

## Elsewhere

- [`sorocharge-core`](https://github.com/sorocharge/sorocharge-core) — the Rust
  crate that does all the signing, verification and XDR work. Behaviour
  questions about *what* is checked belong there; this repo only binds it.
- [`examples/`](../examples/) — runnable scripts. `verify-transfer-effects.php`
  needs no network or keys.
- [CONTRIBUTING.md](../CONTRIBUTING.md) — what belongs in this repo, and what
  does not.
- [SECURITY.md](../SECURITY.md) — private reporting, scope, and the list of
  behaviours that are deliberate rather than bugs.
