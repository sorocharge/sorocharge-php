<?php

declare(strict_types=1);

/**
 * What a payee should check before submitting, and why verifyEntry is not
 * enough on its own.
 *
 *   php -d extension=target/release/libsorocharge.so \
 *       examples/verify-transfer-effects.php
 *
 * Unlike the other examples this one needs no network, no keys and no
 * environment: it replays the diagnostic events of a real Stellar testnet
 * transaction, recorded in tests/fixtures/sac_native_transfer_events.json
 * (tx 50d5c522..., a native-XLM SAC transfer of 1000000 stroops).
 *
 * In a real facilitator the events come from your RPC client instead:
 * build the InvokeHostFunction transaction carrying the verified entry, call
 * simulateTransaction, and pass the `diagnosticEventsXdr` array from the
 * response straight to verifyTransferEffects. Then submit — and only then.
 */

namespace SorochargeExamples;

use Sorocharge\ChargeParams;
use Sorocharge\Sorocharge;
use Sorocharge\SorochargeException;

require __DIR__ . '/../vendor/autoload.php';

$fixture = json_decode(
    (string) file_get_contents(__DIR__ . '/../tests/fixtures/sac_native_transfer_events.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

/** @var list<string> $events */
$events = $fixture['diagnostic_events_xdr_base64'];

/**
 * The charge the payee agreed to. validUntilLedger is not read by the effects
 * check — an event stream carries no expiry — so pass the same ChargeParams
 * you verified the entry against.
 */
$charge = static fn (string $amount, string $recipient): ChargeParams => new ChargeParams(
    assetContract: $fixture['asset_contract'],
    amount: $amount,
    payer: $fixture['payer'],
    recipient: $recipient,
    validUntilLedger: 1,
);

$expected = $charge($fixture['amount'], $fixture['recipient']);

printf("transaction:  %s\n", $fixture['tx_hash']);
printf("events:       %d diagnostic events\n", count($events));
printf("expecting:    %s base units to %s\n\n", $fixture['amount'], $fixture['recipient']);

/** Runs the check and reports which way it went, without stopping the script. */
$check = static function (string $label, array $events, ChargeParams $expected): void {
    try {
        Sorocharge::verifyTransferEffects($events, $expected);
        printf("  %-34s accepted\n", $label);
    } catch (SorochargeException $e) {
        printf("  %-34s refused (%s)\n", $label, (new \ReflectionClass($e))->getShortName());
    }
};

echo "the real transaction's events:\n";
$check('as recorded', $events, $expected);

echo "\nthe same events, checked against a charge the payee did not agree to:\n";
$check('one base unit more', $events, $charge('1000001', $fixture['recipient']));
$check('a different recipient', $events, $charge($fixture['amount'], $fixture['payer']));

echo "\ntampered event streams:\n";
// The transfer is present and correct, but it is not the only balance
// movement any more. "At least one matching transfer" is not the rule: the
// rule is that nothing else moved.
$check('the transfer, twice', array_merge($events, $events), $expected);
$check('an undecodable event appended', [...$events, 'not base64 xdr'], $expected);
$check('no events at all', [], $expected);

echo <<<'TEXT'

Why this matters: verifyEntry reads the signed entry, so it proves the payer
authorized this charge. It cannot see what the transaction will do. The same
invocation can carry the charge you verified plus a second transfer, a mint, a
burn or a clawback, emitted by any contract in the call tree. If you pay the
fee or submit on someone else's behalf, simulate first and check the effects.

TEXT;
