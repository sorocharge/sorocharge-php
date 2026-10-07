<?php

declare(strict_types=1);

namespace Sorocharge;

/**
 * A simulation's events could not be decoded, or a balance event lacks the
 * topics or data a transfer must carry.
 *
 * Raised by sorocharge-core's verify_transfer_effects (facilitator-side
 * simulation checks), which this extension does not expose. Defined so every
 * core error has its own class.
 */
final class SimulationEventsMalformedException extends SorochargeException
{
}
