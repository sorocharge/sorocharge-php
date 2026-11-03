# Taking a charge, end to end

The order of operations for a PHP service that charges per request and settles
on Stellar. This is the caller-side code the library deliberately leaves to
you, with the two library calls in their correct places.

If you only read one thing: **verify the entry, then simulate, then check the
effects, then submit.** Skipping the middle two is the difference between
charging what you quoted and submitting a transaction you have not read.

## The sequence

```
  client                        your service                      network
    |                                 |                               |
    |-- GET /resource --------------->|                               |
    |<------------- 402 + quote ------|  quote: asset, amount,        |
    |                                 |  recipient, expiry ledger     |
    |                                 |                               |
  build + sign the entry              |                               |
    |                                 |                               |
    |-- GET /resource + entry ------->|                               |
    |                                 |- 1. getLatestLedger --------->|
    |                                 |- 2. verifyEntry               |
    |                                 |- 3. seen this entry before?   |
    |                                 |- 4. build the transaction     |
    |                                 |- 5. simulateTransaction ----->|
    |                                 |- 6. verifyTransferEffects     |
    |                                 |- 7. sendTransaction --------->|
    |                                 |- 8. poll getTransaction ----->|
    |<------------- 200 + resource ---|                               |
```

Steps 2 and 6 are this library. Everything else is your RPC client, your
datastore and your framework.

## 1. Quote the charge

Decide the asset, amount and recipient, and the ledger the quote expires at.
Amounts are base units as a **string** — stroops for XLM, so 0.5 XLM is
`'5000000'`. Never a PHP `int`: base-unit amounts can exceed `PHP_INT_MAX`.

```php
$currentLedger = $rpc->getLatestLedger();

$quote = new ChargeParams(
    assetContract: $nativeSac,
    amount: '5000000',
    payer: $payerAddress,
    recipient: $myAddress,
    validUntilLedger: $currentLedger + 60,  // ~5 min of ~5s ledgers
);
```

Return it in your `402` body, in whatever shape your x402 or MPP layer uses.

## 2. Verify the entry that comes back

`$expected->validUntilLedger` is **the latest expiry you accept**, not the
current ledger. Set it to the current ledger plus your window; setting it to
the current ledger rejects every real entry.

```php
use Sorocharge\{ChargeParams, SignedEntry, Sorocharge, SorochargeException};

$currentLedger = $rpc->getLatestLedger();
$expected = new ChargeParams(
    assetContract: $nativeSac,
    amount: '5000000',
    payer: $payerAddress,
    recipient: $myAddress,
    validUntilLedger: $currentLedger + 60,
);

try {
    Sorocharge::verifyEntry(
        SignedEntry::fromXdr($wireXdr),
        $expected,
        $currentLedger,
        Sorocharge::PUBNET_PASSPHRASE,
    );
} catch (SorochargeException $e) {
    // 402 again, with the reason. Do not leak internals to the client;
    // the exception class is the useful part for your own logs.
    return $this->paymentRequired($quote, $e);
}
```

`SignedEntry::fromXdr()` proves nothing — it only decodes. Only `verifyEntry`
establishes anything, so never treat a successful decode as a successful
check.

Catch the specific subclasses where you want to behave differently:
`ExpiredEntryException` is worth re-quoting for, `AmountMismatchException`
usually is not.

## 3. Refuse what you have already accepted

**The library holds no state and cannot do this for you.** A verified entry
keeps verifying until it is submitted on-chain and its nonce is consumed, so
without this step the same entry buys the resource repeatedly.

```php
$fingerprint = hash('sha256', $wireXdr);
if (!$this->seen->addIfAbsent($fingerprint, ttl: $entryWindowSeconds + 60)) {
    return $this->paymentRequired($quote, 'entry already used');
}
```

The record must outlive the entry's validity window. If you accept entries
valid for five more minutes, a record that expires in one minute makes the
entry reusable four minutes later.

## 4–6. Build, simulate, and check what you are about to submit

Attach the entry to an `InvokeHostFunction` operation, simulate, and check the
effects **before** submitting. This is the step that catches a transaction
carrying your charge plus balance movements you never agreed to.

```php
$tx = $this->buildInvokeHostFunction($wireXdr, $sourceAccount);

$simulation = $rpc->simulateTransaction($tx);

try {
    // Pass the array through unfiltered: non-contract events are ignored.
    Sorocharge::verifyTransferEffects($simulation['diagnosticEventsXdr'], $expected);
} catch (SorochargeException $e) {
    // Do not submit. Something other than the agreed transfer moves a
    // balance, or the agreed transfer is not there at all.
    $this->alert('effects check failed before submit', $e);
    return $this->paymentRequired($quote, 'simulation rejected');
}
```

Why it matters even though you built the transaction: the entry authorizes an
invocation of a contract you do not control. That contract can call others,
and any of them can move balances. The simulation is the first point at which
you can see what actually happens.

**If you sponsor the fee, this step is not optional.** You are signing a
transaction whose effects you have not otherwise inspected.

A runnable demonstration, needing no network or keys, is in
[`examples/verify-transfer-effects.php`](../examples/verify-transfer-effects.php):
it replays a real testnet transaction's events and shows each way the check
fails.

## 7–8. Submit, then wait for the ledger

```php
$hash = $rpc->sendTransaction($tx);
$result = $rpc->awaitTransaction($hash, timeout: 30);

if ($result['status'] !== 'SUCCESS') {
    // Not paid. Release the resource hold; decide whether to re-quote.
    return $this->paymentRequired($quote, 'settlement failed');
}
```

**Deliver the resource here, not at step 2.** Verification is a promise;
settlement is the payment. If you deliver on verification you have extended
credit, which may be a deliberate decision — but make it deliberately, and
size it.

If submission fails after you consumed the replay record in step 3, that entry
is now unusable by an honest payer who could have retried. Either record the
fingerprint as provisional and confirm it on settlement, or accept that a
failed submission costs the payer a re-sign.

## Errors, and which are worth retrying

| Exception | What happened | Reasonable response |
|---|---|---|
| `ExpiredEntryException` | The entry expired before you checked | Re-quote; the payer can sign again |
| `ExpirationExceedsAllowanceException` | Valid longer than you accept — or your allowance is set to the current ledger | Check your allowance first; this is usually your bug |
| `AmountMismatchException`, `AssetMismatchException`, `RecipientMismatchException`, `PayerMismatchException` | The entry authorizes a different charge | Re-quote; do not negotiate down |
| `InvalidSignatureException` | Signature does not verify for the passphrase you passed | Check you are using the right network's passphrase before assuming bad faith |
| `UnexpectedInvocationShapeException` | Not a single SEP-41 transfer | Reject |
| `UnexpectedBalanceChangeException` | The simulation moves something else | **Do not submit.** Investigate |
| `ExpectedTransferMissingException` | The simulation does not contain the transfer | Do not submit; your transaction is probably built wrong |
| `SimulationEventsMalformedException` | An event did not decode | Do not submit; suspect your RPC client or the field you passed |
| `InternalErrorException` | A bug in this extension | [Report it](https://github.com/sorocharge/sorocharge-php/issues) |
| `\InvalidArgumentException` | Your own programming error — malformed amount, out-of-range ledger, empty passphrase | Fix the call |

`InvalidArgumentException` sits deliberately outside `SorochargeException`:
`catch (SorochargeException)` means "the payment did not check out", and your
own bad argument is not that.

## Keys

`signEntry` takes a callback that receives the 32-byte preimage and returns
the raw 64-byte ed25519 signature. The library never sees the key:

```php
$signed = Sorocharge::signEntry(
    $unsigned,
    fn (string $preimage): string => $kms->signEd25519($keyId, $preimage),
    $payerAddress,
    Sorocharge::PUBNET_PASSPHRASE,
);
```

An exception your callback throws reaches you unchanged. A fatal error inside
it stops the script exactly as it would anywhere else.

Most servers taking payments never call `signEntry` at all — the payer signs.
You need it if you also pay *out*, or if you are testing your own payee path.

## Further reading

- [threat-model.md](threat-model.md) — what each check proves, in detail
- [../SECURITY.md](../SECURITY.md) — reporting, and what is not a bug
- [`examples/`](../examples/) — runnable round trips
