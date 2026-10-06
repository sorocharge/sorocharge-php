<?php

declare(strict_types=1);

/**
 * Shared plumbing for the examples: environment, the payer's key, and the
 * current ledger. None of this is part of the sorocharge package. It is the
 * caller-side code the library deliberately leaves to you (key handling, RPC),
 * written as plainly as possible.
 *
 * Environment:
 *   SOROCHARGE_NETWORK      required: "testnet" or "pubnet". Never defaulted.
 *   SOROCHARGE_TEST_SECRET  required: the payer's secret seed (S...). Keep it
 *                           in your environment or a gitignored .env, never
 *                           in a committed file.
 *   SOROCHARGE_RPC_URL      Stellar RPC endpoint. Defaults to SDF's public
 *                           endpoint on testnet; required on pubnet.
 *   SOROCHARGE_RECIPIENT    address to charge to (defaults to a fixed test
 *                           address; nothing is submitted, so it needn't exist).
 */

namespace SorochargeExamples;

use Sorocharge\Sorocharge;

require __DIR__ . '/../vendor/autoload.php';

final class Config
{
    /** Native XLM's Stellar Asset Contract on each network. */
    private const NATIVE_SAC = [
        'testnet' => 'CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQVU2HHGCYSC',
        'pubnet' => 'CAS3J7GYLGXMF6TDJBBYYSE3HQ6BBSMLNUQ34T6TZMYMW2EVH34XOWMA',
    ];

    private function __construct(
        public readonly string $network,
        public readonly string $passphrase,
        public readonly string $rpcUrl,
        public readonly string $assetContract,
        public readonly string $recipient,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $network = getenv('SOROCHARGE_NETWORK');
        if ($network !== 'testnet' && $network !== 'pubnet') {
            throw new \RuntimeException('Set SOROCHARGE_NETWORK to "testnet" or "pubnet"; there is no default.');
        }
        $rpcUrl = getenv('SOROCHARGE_RPC_URL') ?: ($network === 'testnet' ? 'https://soroban-testnet.stellar.org' : null);
        if ($rpcUrl === null) {
            throw new \RuntimeException('Set SOROCHARGE_RPC_URL: there is no default public RPC endpoint for pubnet.');
        }

        return new self(
            $network,
            $network === 'testnet' ? Sorocharge::TESTNET_PASSPHRASE : Sorocharge::PUBNET_PASSPHRASE,
            $rpcUrl,
            self::NATIVE_SAC[$network],
            getenv('SOROCHARGE_RECIPIENT') ?: 'GCQJVJPUPJTVTABP7FK7RXBNFIKKLSM5EO7JP6DECJ77SOBUKWSPB64N',
        );
    }

    /** The current ledger sequence, from the network's RPC `getLatestLedger`. */
    public function latestLedger(): int
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'getLatestLedger']),
            'timeout' => 10,
        ]]);
        $body = file_get_contents($this->rpcUrl, false, $context);
        if ($body === false) {
            throw new \RuntimeException("RPC request to {$this->rpcUrl} failed");
        }
        $sequence = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['result']['sequence'] ?? null;
        if (!is_int($sequence)) {
            throw new \RuntimeException("Unexpected getLatestLedger response: $body");
        }
        return $sequence;
    }
}

/**
 * The payer's key. In production, `sign()` would call your KMS or HSM and the
 * secret would never be in this process; here it comes from the environment.
 */
final class PayerKey
{
    private function __construct(private readonly string $secretKey, public readonly string $address)
    {
    }

    public static function fromEnvironment(): self
    {
        $secret = getenv('SOROCHARGE_TEST_SECRET');
        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException('Set SOROCHARGE_TEST_SECRET to the payer\'s secret seed (S...).');
        }
        $keypair = sodium_crypto_sign_seed_keypair(Strkey::decode($secret, 18 << 3)); // version byte 'S'
        return new self(
            sodium_crypto_sign_secretkey($keypair),
            Strkey::encode(sodium_crypto_sign_publickey($keypair), 6 << 3), // version byte 'G'
        );
    }

    /** The `$signPreimage` closure Sorocharge::signEntry takes. */
    public function signer(): \Closure
    {
        return fn (string $preimage): string => sodium_crypto_sign_detached($preimage, $this->secretKey);
    }
}

/** Minimal strkey (SEP-23) encoding for ed25519 keys: version byte, payload, CRC16-XModem, base32. */
final class Strkey
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $payload, int $version): string
    {
        $data = chr($version) . $payload;
        $data .= pack('v', self::crc16($data));
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function decode(string $strkey, int $version): string
    {
        $bits = '';
        foreach (str_split($strkey) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Not a strkey');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $data = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $data .= chr(bindec($byte));
        }
        if (strlen($data) !== 35 || ord($data[0]) !== $version) {
            throw new \InvalidArgumentException('Wrong strkey type or length');
        }
        if (unpack('v', substr($data, 33))[1] !== self::crc16(substr($data, 0, 33))) {
            throw new \InvalidArgumentException('Strkey checksum mismatch');
        }
        return substr($data, 1, 32);
    }

    private static function crc16(string $data): int
    {
        $crc = 0;
        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }
}
