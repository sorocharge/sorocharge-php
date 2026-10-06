<?php

/**
 * IDE and static-analysis stubs for the native `sorocharge` extension.
 *
 * Never loaded at runtime: the extension defines these. The exception
 * hierarchy is plain PHP in php/src/, not part of the extension. Written by
 * hand because `cargo php stubs` (cargo-php 0.2.0) emits broken parent
 * namespaces; tests/integration/StubsTest.php fails if this file and the
 * loaded extension disagree on any class, parent, method, or function.
 */

namespace Sorocharge {
    /**
     * One SEP-41 transfer to build, sign, or verify an authorization entry for.
     * Immutable: validated in the constructor, exposed as read-only properties.
     */
    final class ChargeParams
    {
        /** SEP-41 token contract address (`C...`). */
        public readonly string $assetContract;
        /** Amount in the asset's base units, as a decimal string. */
        public readonly string $amount;
        /** Address authorizing the transfer. */
        public readonly string $payer;
        /** Address receiving the transfer. */
        public readonly string $recipient;
        /** Last ledger sequence the authorization is valid for. */
        public readonly int $validUntilLedger;

        /**
         * @param string $assetContract SEP-41 token contract address (`C...`).
         * @param string $amount Base-unit amount as a canonical non-negative
         *     decimal string (digits only; no sign, whitespace, or leading
         *     zeros). Never an int: amounts can exceed PHP_INT_MAX.
         * @param string $payer Address authorizing the transfer (`G...`/`C...`).
         * @param string $recipient Address receiving the transfer.
         * @param int $validUntilLedger 0 to 4294967295.
         *
         * @throws \InvalidArgumentException malformed amount or ledger, or a
         *     non-contract asset.
         * @throws InvalidAddressException an address is not a valid strkey.
         */
        public function __construct(
            string $assetContract,
            string $amount,
            string $payer,
            string $recipient,
            int $validUntilLedger,
        ) {}
    }

    /** An unsigned charge entry. Only `Sorocharge::buildChargeEntry` creates one. */
    final class UnsignedEntry
    {
        /** Always throws: entries are only created by this library. */
        public function __construct() {}

        /**
         * Base64 `SorobanAuthorizationEntry` XDR, signature still empty.
         *
         * @throws XdrEncodingFailedException
         */
        public function toXdr(): string {}
    }

    /** A signed charge entry, from `Sorocharge::signEntry` or `fromXdr`. */
    final class SignedEntry
    {
        /** Always throws: entries are only created by this library. */
        public function __construct() {}

        /**
         * Decodes base64 `SorobanAuthorizationEntry` XDR received over the
         * wire. Proves nothing about the signature; only verifyEntry does.
         *
         * @throws XdrEncodingFailedException
         */
        public static function fromXdr(string $xdr): SignedEntry {}

        /**
         * Base64 `SorobanAuthorizationEntry` XDR, ready to submit.
         *
         * @throws XdrEncodingFailedException
         */
        public function toXdr(): string {}
    }
}

/**
 * The raw native functions behind the `Sorocharge\Sorocharge` facade. Call the
 * facade instead; these carry no stability promise of their own.
 */
namespace Sorocharge\Native {
    /** @param list<string> $delegates */
    function build_charge_entry(
        \Sorocharge\ChargeParams $params,
        string $credential_kind,
        array $delegates,
    ): \Sorocharge\UnsignedEntry {}

    /** @param callable(string): string $sign_preimage */
    function sign_entry(
        \Sorocharge\UnsignedEntry $entry,
        mixed $sign_preimage,
        string $public_address,
        string $network_passphrase,
    ): \Sorocharge\SignedEntry {}

    function verify_entry(
        \Sorocharge\SignedEntry $entry,
        \Sorocharge\ChargeParams $expected,
        int $current_ledger,
        string $network_passphrase,
    ): void {}
}
