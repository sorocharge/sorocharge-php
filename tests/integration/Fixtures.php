<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use Sorocharge\ChargeParams;

/** Loads sorocharge-core's golden vectors from tests/fixtures/. */
final class Fixtures
{
    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        $json = file_get_contents(__DIR__ . "/../fixtures/$name.json");
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $fixture */
    public static function params(array $fixture): ChargeParams
    {
        return new ChargeParams(
            $fixture['asset_contract'],
            $fixture['amount'],
            $fixture['payer'],
            $fixture['recipient'],
            $fixture['valid_until_ledger'],
        );
    }

    /**
     * The ed25519 secret key behind a golden-vector address: core derives
     * every fixture key from a 32-byte seed of one repeated byte (payer 0x11,
     * recipient 0x22, delegates 0x44 and 0x55). Unfunded, test-only keys.
     */
    public static function secretKey(int $seedByte): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat(chr($seedByte), 32)));
    }

    /** A signing closure in the shape signEntry expects, backed by a fixture key. */
    public static function signer(int $seedByte): \Closure
    {
        $secretKey = self::secretKey($seedByte);
        return static fn (string $preimage): string => sodium_crypto_sign_detached($preimage, $secretKey);
    }
}
