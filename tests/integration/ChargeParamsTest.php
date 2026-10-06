<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sorocharge\ChargeParams;
use Sorocharge\InvalidAddressException;

final class ChargeParamsTest extends TestCase
{
    // Addresses from sorocharge-core's golden vectors (fixed, unfunded seeds).
    public const ASSET = 'CAZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGGJH';
    public const PAYER = 'GDIEVMRSOQV3JKZ2CNUL2RQV4TTNAISKW4NAC25PQUQKGMWJO6DTOAE7';
    public const RECIPIENT = 'GCQJVJPUPJTVTABP7FK7RXBNFIKKLSM5EO7JP6DECJ77SOBUKWSPB64N';

    public function testRoundTripsEveryField(): void
    {
        $params = new ChargeParams(self::ASSET, '1000000000', self::PAYER, self::RECIPIENT, 123456);

        self::assertSame(self::ASSET, $params->assetContract);
        self::assertSame('1000000000', $params->amount);
        self::assertSame(self::PAYER, $params->payer);
        self::assertSame(self::RECIPIENT, $params->recipient);
        self::assertSame(123456, $params->validUntilLedger);
    }

    public function testAcceptsNamedArguments(): void
    {
        $params = new ChargeParams(
            validUntilLedger: 7,
            recipient: self::RECIPIENT,
            payer: self::PAYER,
            amount: '5',
            assetContract: self::ASSET,
        );
        self::assertSame('5', $params->amount);
        self::assertSame(7, $params->validUntilLedger);
    }

    public function testAmountBeyondPhpIntRangeIsPreservedExactly(): void
    {
        // i128::MAX: far past PHP_INT_MAX, which an int would silently mangle.
        $max = '170141183460469231731687303715884105727';
        $params = new ChargeParams(self::ASSET, $max, self::PAYER, self::RECIPIENT, 1);
        self::assertSame($max, $params->amount);
    }

    public function testZeroAmountAndLedgerBoundsAreAccepted(): void
    {
        $params = new ChargeParams(self::ASSET, '0', self::PAYER, self::RECIPIENT, 4294967295);
        self::assertSame('0', $params->amount);
        self::assertSame(4294967295, $params->validUntilLedger);
    }

    /** @return array<string, array{string}> */
    public static function malformedAmounts(): array
    {
        return [
            'empty' => [''],
            'negative' => ['-1'],
            'plus sign' => ['+1'],
            'leading zero' => ['010'],
            'leading space' => [' 10'],
            'trailing newline' => ["10\n"],
            'decimal' => ['10.0'],
            'exponent' => ['1e3'],
            'hex' => ['0x10'],
            'past i128::MAX' => ['170141183460469231731687303715884105728'],
        ];
    }

    #[DataProvider('malformedAmounts')]
    public function testRejectsMalformedAmount(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChargeParams(self::ASSET, $amount, self::PAYER, self::RECIPIENT, 1);
    }

    /** @return array<string, array{int}> */
    public static function outOfRangeLedgers(): array
    {
        return ['negative' => [-1], 'past u32::MAX' => [4294967296]];
    }

    #[DataProvider('outOfRangeLedgers')]
    public function testRejectsOutOfRangeLedger(int $ledger): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChargeParams(self::ASSET, '1', self::PAYER, self::RECIPIENT, $ledger);
    }

    public function testRejectsInvalidStrkey(): void
    {
        $this->expectException(InvalidAddressException::class);
        $this->expectExceptionMessage('GNOTANADDRESS');
        new ChargeParams(self::ASSET, '1', 'GNOTANADDRESS', self::RECIPIENT, 1);
    }

    public function testRejectsAccountAddressAsAsset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contract address');
        new ChargeParams(self::PAYER, '1', self::PAYER, self::RECIPIENT, 1);
    }

    public function testIsReadOnly(): void
    {
        $params = new ChargeParams(self::ASSET, '1', self::PAYER, self::RECIPIENT, 1);
        try {
            $params->amount = '2';
            self::fail('writing a ChargeParams property did not throw');
        } catch (\Throwable) {
            self::assertSame('1', $params->amount);
        }
    }
}
