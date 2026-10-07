<?php

declare(strict_types=1);

namespace Sorocharge;

/**
 * A simulation shows a balance change other than the expected payment.
 *
 * Raised by sorocharge-core's verify_transfer_effects (facilitator-side
 * simulation checks), which this extension does not expose. Defined so every
 * core error has its own class.
 */
final class UnexpectedBalanceChangeException extends SorochargeException
{
}
