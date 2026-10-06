<?php

declare(strict_types=1);

namespace Sorocharge;

/** The entry does not authorize a single SEP-41 transfer(from, to, amount). */
final class UnexpectedInvocationShapeException extends SorochargeException
{
}
