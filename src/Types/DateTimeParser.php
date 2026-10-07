<?php

namespace Doctrine\DBAL\Types;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;

use function preg_match;
use function preg_replace;
use function str_replace;
use function strpos;

/**
 * Parses date/time values returned by the database using the platform format,
 * tolerating fractional seconds the format does not account for.
 *
 * Databases return fractions whenever the column allows them, e.g. PostgreSQL
 * for TIME/TIMESTAMP without a precision modifier, SQL Server for TIME(7) or
 * Oracle for TIMESTAMP(9). PHP cannot represent more than 6 digits, so excess
 * digits are truncated.
 *
 * @internal
 */
final class DateTimeParser
{
    private const MAX_FRACTION_DIGITS = 6;

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }

    /**
     * Returns the parsed value or false if the value matches neither the format nor its fractional variant.
     *
     * @param class-string<T> $className
     *
     * @return T|false
     *
     * @template T of DateTime|DateTimeImmutable
     */
    public static function parse(string $className, string $format, string $value, ?DateTimeZone $timeZone = null)
    {
        $dateTime = $className::createFromFormat($format, $value, $timeZone);

        if ($dateTime !== false) {
            return $dateTime;
        }

        if (preg_match('/:\d\d\.\d/', $value) === 1) {
            $value = preg_replace('/(?<=:\d\d\.\d{' . self::MAX_FRACTION_DIGITS . '})\d+/', '', $value, 1) ?? $value;

            if (strpos($format, 's.u') === false) {
                $format = preg_replace('/(?<!\\\\)s/', 's.u', $format, 1) ?? $format;
            }
        } else {
            $format = str_replace('s.u', 's', $format);
        }

        return $className::createFromFormat($format, $value, $timeZone);
    }
}
