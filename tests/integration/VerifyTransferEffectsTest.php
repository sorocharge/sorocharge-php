<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\TestCase;
use Sorocharge\ChargeParams;
use Sorocharge\ExpectedTransferMissingException;
use Sorocharge\SimulationEventsMalformedException;
use Sorocharge\Sorocharge;
use Sorocharge\UnexpectedBalanceChangeException;

/**
 * Runs the effects check against the diagnostic events of a real Stellar
 * testnet transaction (core's sac_native_transfer_events vector: a native-XLM
 * SAC transfer of 1000000 stroops), then changes one thing at a time.
 *
 * These are the only tests that reach SimulationEventsMalformedException,
 * UnexpectedBalanceChangeException, and ExpectedTransferMissingException:
 * before verifyTransferEffects was bound, the three classes existed but
 * nothing could throw them.
 */
final class VerifyTransferEffectsTest extends TestCase
{
    private const OTHER_ADDRESS = 'GD32WF7OTRHK7UX35EYPYEMYYYVXQCT2HSRDPYG6FMQPGRUJ66MDMQR4';
    private const OTHER_CONTRACT = 'CAZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGMZTGGJH';

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = Fixtures::load('sac_native_transfer_events');
    }

    /** @return list<string> */
    private function events(): array
    {
        return $this->fixture['diagnostic_events_xdr_base64'];
    }

    /**
     * The fixture carries no expiry (an event stream has none), so
     * validUntilLedger is arbitrary here and never read by the check.
     *
     * @param array<string, string> $overrides
     */
    private function expected(array $overrides = []): ChargeParams
    {
        $f = array_merge($this->fixture, $overrides);
        return new ChargeParams(
            $f['asset_contract'],
            $f['amount'],
            $f['payer'],
            $f['recipient'],
            1,
        );
    }

    public function testAcceptsTheRealTestnetTransfer(): void
    {
        Sorocharge::verifyTransferEffects($this->events(), $this->expected());
        $this->addToAssertionCount(1);
    }

    public function testRejectsAnAmountOffByOneBaseUnit(): void
    {
        $this->expectException(UnexpectedBalanceChangeException::class);
        Sorocharge::verifyTransferEffects($this->events(), $this->expected(['amount' => '1000001']));
    }

    public function testRejectsADifferentRecipient(): void
    {
        $this->expectException(UnexpectedBalanceChangeException::class);
        Sorocharge::verifyTransferEffects(
            $this->events(),
            $this->expected(['recipient' => $this->fixture['payer']]),
        );
    }

    public function testRejectsADifferentPayer(): void
    {
        $this->expectException(UnexpectedBalanceChangeException::class);
        Sorocharge::verifyTransferEffects(
            $this->events(),
            $this->expected(['payer' => self::OTHER_ADDRESS]),
        );
    }

    public function testRejectsEventsFromAnotherAssetContract(): void
    {
        $this->expectException(UnexpectedBalanceChangeException::class);
        Sorocharge::verifyTransferEffects(
            $this->events(),
            $this->expected(['asset_contract' => self::OTHER_CONTRACT]),
        );
    }

    /**
     * The same simulation's events presented twice: the transfer now appears
     * twice, which is two balance movements, so the check fails. One transfer
     * of the right shape is not enough — there must be no other.
     */
    public function testRejectsADoubledEventStream(): void
    {
        $doubled = array_merge($this->events(), $this->events());

        $this->expectException(UnexpectedBalanceChangeException::class);
        Sorocharge::verifyTransferEffects($doubled, $this->expected());
    }

    public function testRejectsAnEmptyEventList(): void
    {
        $this->expectException(ExpectedTransferMissingException::class);
        Sorocharge::verifyTransferEffects([], $this->expected());
    }

    /** Non-contract events (fn_call, fn_return and friends) are passed through. */
    public function testRejectsAnEventThatDoesNotDecode(): void
    {
        $events = $this->events();
        $events[] = 'not base64 xdr';

        $this->expectException(SimulationEventsMalformedException::class);
        Sorocharge::verifyTransferEffects($events, $this->expected());
    }

    public function testRejectsANonStringEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line deliberately the wrong element type */
        Sorocharge::verifyTransferEffects([42], $this->expected());
    }

    /**
     * An associative array still works: only the values are read, so a list
     * that lost its keys (json_decode of an object, array_filter output)
     * behaves the same.
     */
    public function testReadsValuesRegardlessOfKeys(): void
    {
        $keyed = [];
        foreach ($this->events() as $i => $event) {
            $keyed["event$i"] = $event;
        }

        Sorocharge::verifyTransferEffects($keyed, $this->expected());
        $this->addToAssertionCount(1);
    }
}
