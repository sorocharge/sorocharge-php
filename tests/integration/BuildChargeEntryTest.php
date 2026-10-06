<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sorocharge\ChargeParams;
use Sorocharge\DuplicateDelegateSignerException;
use Sorocharge\EmptyDelegateSignersException;
use Sorocharge\InvalidAddressException;
use Sorocharge\Sorocharge;
use Sorocharge\UnsignedEntry;

final class BuildChargeEntryTest extends TestCase
{
    /** Byte range of the i64 nonce in every fixture's credential XDR. */
    private const NONCE_OFFSET = 44;
    private const NONCE_LENGTH = 8;

    /** @return array<string, array{string, string}> */
    public static function credentialKinds(): array
    {
        return [
            'legacy' => ['legacy', 'legacy_transfer'],
            'v2' => ['v2', 'address_v2_transfer'],
            'delegated' => ['delegated', 'delegated_transfer'],
        ];
    }

    /**
     * Builds each credential kind from a stellar-sdk golden vector's inputs and
     * byte-diffs the XDR against the SDK's own output. The nonce is random per
     * build, so the fixture's (42) is spliced in before comparing.
     */
    #[DataProvider('credentialKinds')]
    public function testMatchesStellarSdkGoldenVector(string $kind, string $fixtureName): void
    {
        $fixture = Fixtures::load($fixtureName);
        $entry = Sorocharge::buildChargeEntry(
            Fixtures::params($fixture),
            $kind,
            $fixture['delegate_signers'] ?? [],
        );

        self::assertInstanceOf(UnsignedEntry::class, $entry);
        $expected = base64_decode($fixture['unsigned_entry_xdr_base64'], true);
        $actual = base64_decode($entry->toXdr(), true);
        self::assertSame(strlen($expected), strlen($actual));

        $withFixtureNonce = substr_replace(
            $actual,
            substr($expected, self::NONCE_OFFSET, self::NONCE_LENGTH),
            self::NONCE_OFFSET,
            self::NONCE_LENGTH,
        );
        self::assertSame(bin2hex($expected), bin2hex($withFixtureNonce));
    }

    public function testEachBuildGetsAFreshNonce(): void
    {
        $params = Fixtures::params(Fixtures::load('legacy_transfer'));
        $nonce = static fn (UnsignedEntry $e): string =>
            substr(base64_decode($e->toXdr(), true), self::NONCE_OFFSET, self::NONCE_LENGTH);

        self::assertNotSame(
            $nonce(Sorocharge::buildChargeEntry($params, 'legacy')),
            $nonce(Sorocharge::buildChargeEntry($params, 'legacy')),
        );
    }

    public function testRejectsUnknownCredentialKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sorocharge::buildChargeEntry(Fixtures::params(Fixtures::load('legacy_transfer')), 'v3');
    }

    /** @return array<string, array{string}> */
    public static function nonDelegatedKinds(): array
    {
        return ['legacy' => ['legacy'], 'v2' => ['v2']];
    }

    #[DataProvider('nonDelegatedKinds')]
    public function testRejectsDelegatesForNonDelegatedKind(string $kind): void
    {
        $fixture = Fixtures::load('delegated_transfer');
        $this->expectException(\InvalidArgumentException::class);
        Sorocharge::buildChargeEntry(Fixtures::params($fixture), $kind, $fixture['delegate_signers']);
    }

    public function testDelegatedRequiresAtLeastOneDelegate(): void
    {
        $this->expectException(EmptyDelegateSignersException::class);
        Sorocharge::buildChargeEntry(Fixtures::params(Fixtures::load('delegated_transfer')), 'delegated');
    }

    public function testDelegatedRejectsDuplicateDelegate(): void
    {
        $fixture = Fixtures::load('delegated_transfer');
        $delegate = $fixture['delegate_signers'][0];
        $this->expectException(DuplicateDelegateSignerException::class);
        Sorocharge::buildChargeEntry(Fixtures::params($fixture), 'delegated', [$delegate, $delegate]);
    }

    public function testDelegatedRejectsInvalidDelegateAddress(): void
    {
        $this->expectException(InvalidAddressException::class);
        Sorocharge::buildChargeEntry(Fixtures::params(Fixtures::load('delegated_transfer')), 'delegated', ['GNOPE']);
    }

    public function testDelegatedRejectsNonStringDelegate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sorocharge::buildChargeEntry(Fixtures::params(Fixtures::load('delegated_transfer')), 'delegated', [42]);
    }

    public function testUnsignedEntryCannotBeConstructedFromPhp(): void
    {
        $this->expectException(\Throwable::class);
        new UnsignedEntry();
    }
}
