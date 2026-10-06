<?php

declare(strict_types=1);

namespace Sorocharge;

/** The entry's expiry ledger is at or before the current ledger. */
final class ExpiredEntryException extends SorochargeException
{
}
