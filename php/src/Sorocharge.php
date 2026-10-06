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
    /** Network passphrase for Stellar testnet. */
    public const TESTNET_PASSPHRASE = 'Test SDF Network ; September 2015';

    /** Network passphrase for Stellar mainnet (pubnet). */
    public const PUBNET_PASSPHRASE = 'Public Global Stellar Network ; September 2015';

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

    /**
     * Checks that `$entry` authorizes exactly the charge in `$expected`, and
     * returns normally only if it does. Throws for the first failing check,
     * in sorocharge-core's order:
     *
     * 1. ExpiredEntryException: the entry's expiry ledger is at or before
     *    `$currentLedger`.
     * 2. UnexpectedInvocationShapeException: not a single SEP-41 transfer.
     * 3. AssetMismatchException
     * 4. PayerMismatchException: the authorizing address is not the payer.
     * 5. AmountMismatchException
     * 6. RecipientMismatchException
     * 7. InvalidSignatureException: no attached signature verifies for
     *    `$networkPassphrase`.
     *
     * For a delegated entry this proves at least one delegate signature is
     * genuine; the account contract's own signing policy is checked on-chain.
     *
     * @throws SorochargeException (one of the subclasses above), or
     *     UnsupportedCredentialTypeException for a source-account credential.
     * @throws \InvalidArgumentException for a negative or out-of-range
     *     `$currentLedger`, or an empty network passphrase.
     */
    public static function verifyEntry(
        SignedEntry $entry,
        ChargeParams $expected,
        int $currentLedger,
        string $networkPassphrase,
    ): void {
        Native\verify_entry($entry, $expected, $currentLedger, $networkPassphrase);
    }
}
