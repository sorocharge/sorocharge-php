<?php

declare(strict_types=1);

namespace Sorocharge;

/**
 * Build, sign, and verify Soroban authorization entries for a single SEP-41
 * charge, backed by the native `sorocharge` extension.
 *
 * Every byte of XDR and every signature check comes from sorocharge-core
 * (Rust); this class only gives the native functions a typed, documented
 * entry point. There is no state: every call takes all it needs.
 */
final class Sorocharge
{
    private function __construct()
    {
    }

    /**
     * Builds an unsigned authorization entry for `$params`.
     *
     * @param 'legacy'|'v2'|'delegated' $credentialKind Credential shape: legacy
     *     `Address`, CAP-71 `AddressV2` (mandatory from protocol 28), or CAP-71
     *     delegated signers.
     * @param list<string> $delegates Delegate signer addresses. Required and
     *     non-empty for `'delegated'`; must be empty otherwise.
     *
     * @throws \InvalidArgumentException for an unknown credential kind, or
     *     delegates passed with a kind that doesn't use them.
     * @throws EmptyDelegateSignersException for `'delegated'` with no delegates.
     * @throws DuplicateDelegateSignerException if a delegate is listed twice.
     * @throws InvalidAddressException for a delegate that isn't a valid strkey.
     * @throws SorochargeException for any other failure in sorocharge-core.
     */
    public static function buildChargeEntry(
        ChargeParams $params,
        string $credentialKind,
        array $delegates = [],
    ): UnsignedEntry {
        return Native\build_charge_entry($params, $credentialKind, $delegates);
    }
}
