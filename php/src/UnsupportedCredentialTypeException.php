<?php

declare(strict_types=1);

namespace Sorocharge;

/** The entry's credential shape is not one this library signs or verifies. */
final class UnsupportedCredentialTypeException extends SorochargeException
{
}
