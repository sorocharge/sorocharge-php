<?php

declare(strict_types=1);

namespace Sorocharge;

/** A strkey (G..., C..., M...) could not be decoded into an address. */
final class InvalidAddressException extends SorochargeException
{
}
