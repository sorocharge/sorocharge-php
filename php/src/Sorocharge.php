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
     * Signs `$entry` with a signature produced by `$signPreimage`.
     *
     * The private key never passes through this library: `$signPreimage` is
     * called once with the raw 32-byte signing preimage (a binary string) and
     * must return the raw 64-byte ed25519 signature over it, from wherever
     * the key lives (KMS, HSM, env var). An exception it throws reaches the
     * caller unchanged.
     *
     * @param callable(string): string $signPreimage
     * @param string $publicAddress The signer's `G...` address: the payer, or
     *     for a delegated entry, one of its delegates.
     * @param string $networkPassphrase The passphrase of the network the
     *     entry will be submitted to (see the *_PASSPHRASE constants). It is
     *     hashed into what gets signed, so a signature for one network is
     *     rejected on any other.
     *
     * @throws NoMatchingCredentialNodeException if `$publicAddress` is neither
     *     the entry's payer nor one of its delegates.
     * @throws SigningFailedException if `$signPreimage` returns anything but a
     *     64-byte string, or `$publicAddress` is not an account (`G...`).
     * @throws InvalidAddressException if `$publicAddress` is not a valid strkey.
     * @throws \InvalidArgumentException for an empty network passphrase.
     */
    public static function signEntry(
        UnsignedEntry $entry,
        callable $signPreimage,
        string $publicAddress,
        string $networkPassphrase,
    ): SignedEntry {
        return Native\sign_entry($entry, $signPreimage, $publicAddress, $networkPassphrase);
    }

    /**
     * Checks that `$entry` authorizes exactly the charge in `$expected`, and
     * returns normally only if it does. Throws for the first failing check,
     * in sorocharge-core's order:
     *
     * 1. ExpiredEntryException: the entry's expiry ledger is at or before
     *    `$currentLedger`.
     * 2. ExpirationExceedsAllowanceException: the entry stays valid past
     *    `$expected->validUntilLedger`, i.e. longer than you agreed to accept.
     *    Set it to the latest expiry you will honor, not the current ledger.
     * 3. UnexpectedInvocationShapeException: not a single SEP-41 transfer.
     * 4. AssetMismatchException
     * 5. PayerMismatchException: the authorizing address is not the payer.
     * 6. AmountMismatchException
     * 7. RecipientMismatchException
     * 8. InvalidSignatureException: no attached signature verifies for
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
