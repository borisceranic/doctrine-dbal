<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Implementations store date/time values with fractional seconds and declare a fractional seconds precision of
 * {@see self::DEFAULT_PRECISION} unless the column specifies one.
 *
 * @internal
 */
interface FractionalSecondsType
{
    /** The highest precision a PHP date/time value can represent, i.e. microseconds. */
    public const DEFAULT_PRECISION = 6;
}
