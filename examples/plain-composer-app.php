<?php

declare(strict_types=1);

/**
 * A full build -> sign -> verify round trip, against the live ledger of the
 * network named in SOROCHARGE_NETWORK.
 *
 *   SOROCHARGE_NETWORK=testnet SOROCHARGE_TEST_SECRET=S... \
 *       php -d extension=target/release/libsorocharge.so examples/plain-composer-app.php
 *
 * Payer side: build an entry authorizing a 0.5 XLM transfer, valid for about
 * five minutes of ledgers, and sign it. Payee side: decode it from the wire
 * and verify it against the charge the payee expected. Nothing is submitted:
 * putting the entry in a transaction is the job of your x402/MPP layer.
 */

namespace SorochargeExamples;

use Sorocharge\ChargeParams;
use Sorocharge\SignedEntry;
use Sorocharge\Sorocharge;
use Sorocharge\SorochargeException;

require __DIR__ . '/bootstrap.php';

$config = Config::fromEnvironment();
$payer = PayerKey::fromEnvironment();

// About five minutes of ~5-second ledgers.
$currentLedger = $config->latestLedger();
$charge = new ChargeParams(
    assetContract: $config->assetContract,
    amount: '5000000', // 0.5 XLM in stroops, always as a string
    payer: $payer->address,
    recipient: $config->recipient,
    validUntilLedger: $currentLedger + 60,
);

// Payer: build and sign. AddressV2 credentials are mandatory from protocol 28.
$unsigned = Sorocharge::buildChargeEntry($charge, 'v2');
$signed = Sorocharge::signEntry($unsigned, $payer->signer(), $payer->address, $config->passphrase);
$wire = $signed->toXdr();

printf("network:        %s (ledger %d)\n", $config->network, $currentLedger);
printf("payer:          %s\n", $payer->address);
printf("signed entry:   %s...\n", substr($wire, 0, 48));

// Payee: decode what arrived and check it is exactly the charge it expected.
// validUntilLedger here is the latest expiry the payee accepts; an entry valid
// for longer is refused with ExpirationExceedsAllowanceException.
$expected = new ChargeParams($config->assetContract, '5000000', $payer->address, $config->recipient, $currentLedger + 60);
try {
    Sorocharge::verifyEntry(SignedEntry::fromXdr($wire), $expected, $config->latestLedger(), $config->passphrase);
    echo "verified:       yes\n";
} catch (SorochargeException $e) {
    printf("verified:       NO (%s: %s)\n", $e::class, $e->getMessage());
    exit(1);
}

// The same entry presented for a different amount is refused.
$overcharge = new ChargeParams($config->assetContract, '5000001', $payer->address, $config->recipient, $currentLedger + 60);
try {
    Sorocharge::verifyEntry(SignedEntry::fromXdr($wire), $overcharge, $config->latestLedger(), $config->passphrase);
    echo "overcharge:     ACCEPTED (this is a bug)\n";
    exit(1);
} catch (\Sorocharge\AmountMismatchException $e) {
    echo "overcharge:     refused ({$e->getMessage()})\n";
}
