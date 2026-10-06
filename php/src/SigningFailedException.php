<?php

declare(strict_types=1);

namespace Sorocharge;

/** The signing callback failed, or returned something other than a 64-byte string. */
final class SigningFailedException extends SorochargeException
{
}
