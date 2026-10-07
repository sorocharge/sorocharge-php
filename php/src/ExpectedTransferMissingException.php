<?php

declare(strict_types=1);

namespace Sorocharge;

/**
 * A simulation does not show the expected payment transfer at all.
 *
 * Raised by sorocharge-core's verify_transfer_effects (facilitator-side
 * simulation checks), which this extension does not expose. Defined so every
 * core error has its own class.
 */
final class ExpectedTransferMissingException extends SorochargeException
{
}
