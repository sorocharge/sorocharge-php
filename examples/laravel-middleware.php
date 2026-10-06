<?php

declare(strict_types=1);

/**
 * Laravel middleware that answers HTTP 402 until a request carries a signed
 * charge entry authorizing exactly the configured price, plus the client-side
 * code that produces one. The sorocharge package itself has no framework
 * dependency; this file is the only place Laravel appears.
 *
 * Wiring (Laravel 11+), in routes/web.php:
 *
 *   Route::get('/report', ReportController::class)
 *       ->middleware(RequireCharge::class . ':5000000');   // 0.5 XLM, in stroops
 *
 * and in config/services.php:
 *
 *   'sorocharge' => [
 *       'network'   => env('SOROCHARGE_NETWORK'),        // "testnet" or "pubnet"
 *       'rpc_url'   => env('SOROCHARGE_RPC_URL', 'https://soroban-testnet.stellar.org'),
 *       'asset'     => env('SOROCHARGE_ASSET'),           // SAC address, C...
 *       'recipient' => env('SOROCHARGE_RECIPIENT'),       // your address, G...
 *   ],
 *
 * The header carries base64 SorobanAuthorizationEntry XDR. Real x402 or MPP
 * framing wraps that entry in its own JSON. Encoding and decoding that framing,
 * and submitting the entry on-chain, are your protocol layer's job.
 *
 * Read before using this in production:
 *
 * - Verification is not settlement. A valid entry proves the payer authorized
 *   the transfer; the money moves only when it is submitted on-chain, which
 *   also consumes its nonce. Until then the same entry verifies on every
 *   request, so this middleware refuses any entry it has already accepted
 *   (Cache::add). Submit accepted entries promptly.
 * - verifyEntry does not bound how far in the future an entry may expire;
 *   it only rejects expired ones. The cache entry below lives for a day; an
 *   entry with a longer expiry could be replayed after that. Keep the cache
 *   TTL at least as long as the longest expiry you will settle.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sorocharge\ChargeParams;
use Sorocharge\SignedEntry;
use Sorocharge\Sorocharge;
use Sorocharge\SorochargeException;
use Symfony\Component\HttpFoundation\Response;

final class RequireCharge
{
    public const HEADER = 'X-Sorocharge-Entry';

    /** @param string $amount Price in the asset's base units, from the route definition. */
    public function handle(Request $request, Closure $next, string $amount): Response
    {
        $config = config('services.sorocharge');
        $passphrase = match ($config['network'] ?? null) {
            'testnet' => Sorocharge::TESTNET_PASSPHRASE,
            'pubnet' => Sorocharge::PUBNET_PASSPHRASE,
            default => throw new \RuntimeException('services.sorocharge.network must be "testnet" or "pubnet"'),
        };

        $xdr = $request->header(self::HEADER);
        if (!is_string($xdr) || $xdr === '') {
            return $this->paymentRequired($config, $amount, 'missing ' . self::HEADER);
        }
        $payer = $request->header('X-Sorocharge-Payer');
        if (!is_string($payer) || $payer === '') {
            return $this->paymentRequired($config, $amount, 'missing X-Sorocharge-Payer');
        }

        try {
            $currentLedger = $this->latestLedger($config['rpc_url']);
            // validUntilLedger is not compared by verifyEntry; the entry's own
            // expiry is checked against $currentLedger instead.
            $expected = new ChargeParams($config['asset'], $amount, $payer, $config['recipient'], $currentLedger);
            Sorocharge::verifyEntry(SignedEntry::fromXdr($xdr), $expected, $currentLedger, $passphrase);
        } catch (SorochargeException | \InvalidArgumentException $e) {
            // The class says which check failed (AmountMismatchException,
            // ExpiredEntryException, ...): log it, don't echo internals.
            report($e);
            return $this->paymentRequired($config, $amount, 'payment authorization rejected');
        }

        if (!Cache::add('sorocharge:seen:' . hash('sha256', $xdr), true, now()->addDay())) {
            return $this->paymentRequired($config, $amount, 'payment authorization already used');
        }

        // Hand the entry to your settlement queue here, then serve the request.
        $request->attributes->set('sorocharge.signed_entry', $xdr);
        return $next($request);
    }

    /** @param array<string, string> $config */
    private function paymentRequired(array $config, string $amount, string $reason): Response
    {
        return response()->json([
            'error' => $reason,
            'charge' => [
                'network' => $config['network'],
                'asset' => $config['asset'],
                'amount' => $amount,
                'recipient' => $config['recipient'],
                'credential' => 'v2',
            ],
        ], 402);
    }

    private function latestLedger(string $rpcUrl): int
    {
        $sequence = Http::timeout(10)
            ->post($rpcUrl, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'getLatestLedger'])
            ->throw()
            ->json('result.sequence');
        if (!is_int($sequence)) {
            throw new \RuntimeException('Unexpected getLatestLedger response');
        }
        return $sequence;
    }
}

/**
 * The paying side: turn a 402 response's charge into the two headers above.
 * `$signPreimage` is where your key lives; it receives 32 bytes and returns a
 * 64-byte ed25519 signature (see examples/bootstrap.php for a libsodium one).
 *
 * @param array{asset: string, amount: string, recipient: string} $charge
 * @return array<string, string> headers to retry the request with
 */
function payFor(array $charge, string $payerAddress, callable $signPreimage, int $currentLedger, string $passphrase): array
{
    $unsigned = Sorocharge::buildChargeEntry(
        new ChargeParams($charge['asset'], $charge['amount'], $payerAddress, $charge['recipient'], $currentLedger + 60),
        'v2',
    );
    $signed = Sorocharge::signEntry($unsigned, $signPreimage, $payerAddress, $passphrase);

    return [
        RequireCharge::HEADER => $signed->toXdr(),
        'X-Sorocharge-Payer' => $payerAddress,
    ];
}
