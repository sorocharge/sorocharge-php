<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\TestCase;
use Sorocharge\AmountMismatchException;
use Sorocharge\AssetMismatchException;
use Sorocharge\ChargeParams;
use Sorocharge\ExpirationExceedsAllowanceException;
use Sorocharge\ExpiredEntryException;
use Sorocharge\InvalidSignatureException;
use Sorocharge\PayerMismatchException;
use Sorocharge\RecipientMismatchException;
use Sorocharge\SignedEntry;
use Sorocharge\Sorocharge;
use Sorocharge\UnexpectedInvocationShapeException;
use Sorocharge\XdrEncodingFailedException;

/**
 * Verifies an entry signed by stellar-sdk's own authorizeEntry (core's
 * legacy_transfer_signed golden vector), then breaks it one check at a time.
 */
final class VerifyEntryTest extends TestCase
{
    private const OTHER_CONTRACT = 'CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQVU2HHGCYSC';

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = Fixtures::load('legacy_transfer_signed');
    }

    private function entry(?string $xdr = null): SignedEntry
    {
        return SignedEntry::fromXdr($xdr ?? $this->fixture['signed_entry_xdr_base64']);
    }

    /** @param array<string, mixed> $overrides */
    private function expected(array $overrides = []): ChargeParams
    {
        return Fixtures::params(array_merge($this->fixture, $overrides));
    }

    private function verify(SignedEntry $entry, ChargeParams $expected, int $currentLedger = 1000, ?string $passphrase = null): void
    {
        Sorocharge::verifyEntry($entry, $expected, $currentLedger, $passphrase ?? $this->fixture['network_passphrase']);
    }

    /** Applies $edit to the raw XDR bytes of the golden signed entry. */
    private function tampered(callable $edit): SignedEntry
    {
        $bytes = base64_decode($this->fixture['signed_entry_xdr_base64'], true);
        $edited = $edit($bytes);
        self::assertNotSame($bytes, $edited, 'tamper had no effect');
        return $this->entry(base64_encode($edited));
    }

    public function testStellarSdkSignedEntryVerifies(): void
    {
        $this->verify($this->entry(), $this->expected());
        $this->addToAssertionCount(1);
    }

    public function testFromXdrRoundTripsExactly(): void
    {
        self::assertSame($this->fixture['signed_entry_xdr_base64'], $this->entry()->toXdr());
    }

    public function testFromXdrRejectsGarbage(): void
    {
        $this->expectException(XdrEncodingFailedException::class);
        SignedEntry::fromXdr('not xdr');
    }

    public function testExpiredAtTheExpiryLedger(): void
    {
        $this->expectException(ExpiredEntryException::class);
        $this->verify($this->entry(), $this->expected(), $this->fixture['valid_until_ledger']);
    }

    /**
     * An entry that is not yet expired but stays valid past what the payee
     * agreed to accept. Signed here (not the golden vector) so its expiry is
     * chosen by this test.
     */
    public function testRejectsEntryValidLongerThanExpectedAllows(): void
    {
        $currentLedger = 1000;
        $entryExpiry = $currentLedger + 500;
        $allowance = $currentLedger + 100; // above currentLedger, below the entry's expiry

        $signed = $this->signFreshEntry($entryExpiry);
        $expected = $this->expected(['valid_until_ledger' => $allowance]);

        try {
            $this->verify($signed, $expected, $currentLedger);
            self::fail('an entry valid past the allowance verified');
        } catch (ExpirationExceedsAllowanceException $e) {
            self::assertStringContainsString((string) $entryExpiry, $e->getMessage());
            self::assertStringContainsString((string) $allowance, $e->getMessage());
        }
    }

    public function testAcceptsEntryExpiringExactlyAtOrBeforeTheAllowance(): void
    {
        $signed = $this->signFreshEntry(1500);
        $this->verify($signed, $this->expected(['valid_until_ledger' => 1500]), 1000);
        $this->verify($signed, $this->expected(['valid_until_ledger' => 9000]), 1000);
        $this->addToAssertionCount(2);
    }

    public function testAllowanceIsCheckedRightAfterExpiry(): void
    {
        // Over-long and the wrong amount: the allowance check comes first.
        $signed = $this->signFreshEntry(1500);
        $this->expectException(ExpirationExceedsAllowanceException::class);
        $this->verify($signed, $this->expected(['valid_until_ledger' => 1100, 'amount' => '1']), 1000);
    }

    private function signFreshEntry(int $validUntilLedger): SignedEntry
    {
        $unsigned = Sorocharge::buildChargeEntry(
            $this->expected(['valid_until_ledger' => $validUntilLedger]),
            'v2',
        );
        return Sorocharge::signEntry(
            $unsigned,
            Fixtures::signer(0x11),
            $this->fixture['payer'],
            $this->fixture['network_passphrase'],
        );
    }

    public function testRejectsNonTransferInvocation(): void
    {
        $entry = $this->tampered(static fn (string $b): string => str_replace('transfer', 'transfex', $b));
        $this->expectException(UnexpectedInvocationShapeException::class);
        $this->verify($entry, $this->expected());
    }

    public function testRejectsAssetMismatch(): void
    {
        $this->expectException(AssetMismatchException::class);
        $this->verify($this->entry(), $this->expected(['asset_contract' => self::OTHER_CONTRACT]));
    }

    public function testRejectsPayerMismatch(): void
    {
        $this->expectException(PayerMismatchException::class);
        $this->verify($this->entry(), $this->expected(['payer' => $this->fixture['recipient']]));
    }

    public function testRejectsAmountMismatchByOneBaseUnit(): void
    {
        $this->expectException(AmountMismatchException::class);
        $this->verify($this->entry(), $this->expected(['amount' => '1000000001']));
    }

    public function testRejectsRecipientMismatch(): void
    {
        $this->expectException(RecipientMismatchException::class);
        $this->verify($this->entry(), $this->expected(['recipient' => $this->fixture['payer']]));
    }

    public function testRejectsTamperedSignature(): void
    {
        $signature = base64_decode($this->fixture['signed_entry_xdr_base64'], true);
        $entry = $this->tampered(static function (string $b): string {
            // First byte of the 64-byte signature value, after its "signature" map key.
            $at = strpos($b, 'signature') + strlen("signature\0\0\0") + 8;
            $b[$at] = chr(ord($b[$at]) ^ 0x01);
            return $b;
        });
        $this->expectException(InvalidSignatureException::class);
        $this->verify($entry, $this->expected());
    }

    public function testRejectsSignatureForAnotherNetwork(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->verify($this->entry(), $this->expected(), 1000, Sorocharge::PUBNET_PASSPHRASE);
    }

    public function testChecksRunInCoreOrder(): void
    {
        // Expired and wrong amount: expiry is checked first.
        $this->expectException(ExpiredEntryException::class);
        $this->verify($this->entry(), $this->expected(['amount' => '1']), PHP_INT_MAX >> 32);
    }

    public function testRejectsOutOfRangeCurrentLedger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->verify($this->entry(), $this->expected(), -1);
    }

    public function testRejectsEmptyNetworkPassphrase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->verify($this->entry(), $this->expected(), 1000, '');
    }
}
