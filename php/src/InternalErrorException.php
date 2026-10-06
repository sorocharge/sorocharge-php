<?php

declare(strict_types=1);

namespace Sorocharge;

/** A Rust panic or engine fault inside the extension. Always a sorocharge bug, never a property of the input: please report it. */
final class InternalErrorException extends SorochargeException
{
}
