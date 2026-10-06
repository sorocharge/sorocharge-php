<?php

declare(strict_types=1);

namespace Sorocharge;

/**
 * Base class of every exception reporting a sorocharge-core failure. Catch it
 * to handle any of them, or a subclass to handle one. Input this library
 * rejects before reaching the core throws \InvalidArgumentException instead.
 */
abstract class SorochargeException extends \Exception
{
}
