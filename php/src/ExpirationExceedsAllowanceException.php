<?php

declare(strict_types=1);

namespace Sorocharge;

/**
 * The entry is not expired, but stays valid past the expected charge's
 * validUntilLedger: it authorizes for longer than the payee agreed to accept.
 */
final class ExpirationExceedsAllowanceException extends SorochargeException
{
}
