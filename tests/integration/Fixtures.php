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
}
