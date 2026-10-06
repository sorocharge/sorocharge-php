<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sorocharge\InvalidSignatureException;
use Sorocharge\NoMatchingCredentialNodeException;
use Sorocharge\SignedEntry;
use Sorocharge\SigningFailedException;
use Sorocharge\Sorocharge;
use Sorocharge\UnsignedEntry;

/**
 * signEntry is where a PHP closure crosses the FFI boundary. Every happy-path
 * test here proves the signature by verifying it through verifyEntry.
 */
final class SignEntryTest extends TestCase
{
    private const PASSPHRASE = Sorocharge::TESTNET_PASSPHRASE;

    private static function unsigned(string $fixtureName, string $kind): UnsignedEntry
    {
        $fixture = Fixtures::load($fixtureName);
        return Sorocharge::buildChargeEntry(Fixtures::params($fixture), $kind, $fixture['delegate_signers'] ?? []);
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function signableEntries(): array
    {
        $delegated = Fixtures::load('delegated_transfer');
        return [
            'legacy, payer key' => ['legacy_transfer', 'legacy', 0x11, Fixtures::load('legacy_transfer')['payer']],
            'v2, payer key' => ['address_v2_transfer', 'v2', 0x11, Fixtures::load('address_v2_transfer')['payer']],
            // The fixture lists delegates sorted by address: seed 0x55 first, 0x44 second.
            'delegated, first delegate' => ['delegated_transfer', 'delegated', 0x55, $delegated['delegate_signers'][0]],
            'delegated, second delegate' => ['delegated_transfer', 'delegated', 0x44, $delegated['delegate_signers'][1]],
        ];
    }

    #[DataProvider('signableEntries')]
    public function testSignatureRoundTripsThroughVerifyEntry(string $fixtureName, string $kind, int $seed, string $address): void
    {
        $fixture = Fixtures::load($fixtureName);
        $preimages = [];
        $sign = Fixtures::signer($seed);

        $signed = Sorocharge::signEntry(
            self::unsigned($fixtureName, $kind),
            static function (string $preimage) use ($sign, &$preimages): string {
                $preimages[] = $preimage;
                return $sign($preimage);
            },
            $address,
            self::PASSPHRASE,
        );

        self::assertInstanceOf(SignedEntry::class, $signed);
        self::assertCount(1, $preimages, 'the signing closure must be called exactly once');
        self::assertSame(32, strlen($preimages[0]));

        Sorocharge::verifyEntry($signed, Fixtures::params($fixture), 1000, self::PASSPHRASE);

        // The serialized entry survives the wire and still verifies.
        Sorocharge::verifyEntry(SignedEntry::fromXdr($signed->toXdr()), Fixtures::params($fixture), 1000, self::PASSPHRASE);
    }

    public function testSignatureIsBoundToTheNetwork(): void
    {
        $fixture = Fixtures::load('legacy_transfer');
        $signed = Sorocharge::signEntry(self::unsigned('legacy_transfer', 'legacy'), Fixtures::signer(0x11), $fixture['payer'], self::PASSPHRASE);

        $this->expectException(InvalidSignatureException::class);
        Sorocharge::verifyEntry($signed, Fixtures::params($fixture), 1000, Sorocharge::PUBNET_PASSPHRASE);
    }

    public function testWrongKeyForTheAddressFailsVerification(): void
    {
        $fixture = Fixtures::load('legacy_transfer');
        // Signed by the recipient's key (0x22) but claiming the payer's address.
        $signed = Sorocharge::signEntry(self::unsigned('legacy_transfer', 'legacy'), Fixtures::signer(0x22), $fixture['payer'], self::PASSPHRASE);

        $this->expectException(InvalidSignatureException::class);
        Sorocharge::verifyEntry($signed, Fixtures::params($fixture), 1000, self::PASSPHRASE);
    }

    public function testClosureExceptionReachesTheCallerUnchanged(): void
    {
        $original = new \RuntimeException('KMS unavailable');
        try {
            Sorocharge::signEntry(
                self::unsigned('legacy_transfer', 'legacy'),
                static fn (string $p): string => throw $original,
                Fixtures::load('legacy_transfer')['payer'],
                self::PASSPHRASE,
            );
            self::fail('signEntry returned despite the closure throwing');
        } catch (\RuntimeException $e) {
            self::assertSame($original, $e);
        }
    }

    /** @return array<string, array{mixed}> */
    public static function badSignatures(): array
    {
        return [
            'too short' => [str_repeat("\0", 63)],
            'too long' => [str_repeat("\0", 65)],
            'empty' => [''],
            'not a string' => [64],
            'null' => [null],
        ];
    }

    #[DataProvider('badSignatures')]
    public function testRejectsClosureReturningSomethingOtherThan64Bytes(mixed $returned): void
    {
        $this->expectException(SigningFailedException::class);
        Sorocharge::signEntry(
            self::unsigned('legacy_transfer', 'legacy'),
            static fn (string $p): mixed => $returned,
            Fixtures::load('legacy_transfer')['payer'],
            self::PASSPHRASE,
        );
    }

    public function testDelegateSignedWithAnotherDelegatesKeyFailsVerification(): void
    {
        $fixture = Fixtures::load('delegated_transfer');
        $signed = Sorocharge::signEntry(
            self::unsigned('delegated_transfer', 'delegated'),
            Fixtures::signer(0x44),
            $fixture['delegate_signers'][0],
            self::PASSPHRASE,
        );

        $this->expectException(InvalidSignatureException::class);
        Sorocharge::verifyEntry($signed, Fixtures::params($fixture), 1000, self::PASSPHRASE);
    }

    public function testRejectsAddressWithNoCredentialNode(): void
    {
        $this->expectException(NoMatchingCredentialNodeException::class);
        Sorocharge::signEntry(
            self::unsigned('legacy_transfer', 'legacy'),
            Fixtures::signer(0x22),
            Fixtures::load('legacy_transfer')['recipient'],
            self::PASSPHRASE,
        );
    }

    public function testRejectsContractAddressAsSigner(): void
    {
        $this->expectException(SigningFailedException::class);
        Sorocharge::signEntry(
            self::unsigned('legacy_transfer', 'legacy'),
            Fixtures::signer(0x11),
            Fixtures::load('legacy_transfer')['asset_contract'],
            self::PASSPHRASE,
        );
    }

    public function testRejectsEmptyNetworkPassphrase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sorocharge::signEntry(
            self::unsigned('legacy_transfer', 'legacy'),
            Fixtures::signer(0x11),
            Fixtures::load('legacy_transfer')['payer'],
            '',
        );
    }

    /**
     * A fatal error inside the closure is an engine bailout (a longjmp). It
     * must stop the script like any fatal error: not crash PHP, and not be
     * turned into a catchable exception. Run in a child process because the
     * fatal error ends it.
     */
    public function testFatalErrorInClosureStopsTheScriptCleanly(): void
    {
        $script = <<<'PHP'
            require getenv('SOROCHARGE_AUTOLOAD');
            $f = \Sorocharge\Tests\Fixtures::load('legacy_transfer');
            $entry = \Sorocharge\Sorocharge::buildChargeEntry(\Sorocharge\Tests\Fixtures::params($f), 'legacy');
            register_shutdown_function(static function (): void { echo "shutdown-ran\n"; });
            try {
                \Sorocharge\Sorocharge::signEntry($entry, static fn (string $p): string => str_repeat('x', 256 * 1024 * 1024), $f['payer'], 'Test SDF Network ; September 2015');
            } catch (\Throwable $e) {
                echo "caught\n";
            }
            echo "continued\n";
            PHP;

        $command = [
            PHP_BINARY,
            '-n',
            '-d', 'extension=' . self::extensionPath(),
            '-d', 'memory_limit=32M',
            '-r', $script,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'SOROCHARGE_AUTOLOAD' => dirname(__DIR__, 2) . '/vendor/autoload.php',
        ]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(255, $exitCode, "expected a fatal-error exit, got $exitCode; stderr: $stderr");
        self::assertStringContainsString('Allowed memory size', $stdout . $stderr);
        self::assertStringContainsString('shutdown-ran', $stdout);
        self::assertStringNotContainsString('caught', $stdout);
        self::assertStringNotContainsString('continued', $stdout);
    }

    private static function extensionPath(): string
    {
        $path = getenv('SOROCHARGE_EXTENSION');
        self::assertIsString($path, 'SOROCHARGE_EXTENSION must point at the built extension');
        return $path;
    }
}
