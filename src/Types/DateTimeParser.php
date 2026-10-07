<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;

use function preg_replace;
use function str_contains;
use function str_replace;
use function strpos;
use function strspn;
use function substr;

/**
 * Parses date/time values that the platform format does not parse because of fractional seconds.
 *
 * Databases return fractions whenever the column allows them, e.g. PostgreSQL
 * for TIME/TIMESTAMP without a precision modifier, SQL Server for TIME(7) or
 * Oracle for TIMESTAMP(9). PHP cannot represent more than 6 digits, so excess
 * digits are truncated.
 *
 * The types try the platform format themselves and call this parser only when that fails, or when the value has a
 * fraction the format lacks. Calling the parser for every value would be simpler, but the extra function call made
 * the common case, a value in the platform format, about 10-20% slower in benchmarks. The types also skip the platform
 * format for a value with an unexpected fraction, because failing on the fraction can be costly: a timezone offset
 * ("O") then tries to parse the fraction as a timezone name, which takes several microseconds.
 *
 * @internal
 */
final class DateTimeParser
{
    private const MAX_FRACTION_DIGITS = 6;

    /** @var array<string, string> */
    private static array $fractionalFormats = [];

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }

    /**
     * Parses a value that did not match the format, or that has fractional seconds the format does not account for.
     *
     * Returns false if the value matches neither the format nor its fractional variant.
     *
     * @param class-string<T> $className
     *
     * @return T|false
     *
     * @template T of DateTime|DateTimeImmutable
     */
    public static function parse(
        string $className,
        string $format,
        string $value,
        ?DateTimeZone $timeZone = null,
    ): DateTime|DateTimeImmutable|false {
        $dot = strpos($value, '.');

        if ($dot === false) {
            // a format with fractional seconds rejects values without them
            if (! str_contains($format, '.u')) {
                return false;
            }

            return $className::createFromFormat(str_replace('.u', '', $format), $value, $timeZone);
        }

        $fractionalFormat = self::getFractionalFormat($format);
        $fraction         = $value;
        $digits           = strspn($value, '0123456789', $dot + 1);

        if ($digits > self::MAX_FRACTION_DIGITS) {
            $fraction = substr($value, 0, $dot + 1 + self::MAX_FRACTION_DIGITS) . substr($value, $dot + 1 + $digits);
        } elseif ($fractionalFormat === $format) {
            // the format accounts for the fraction, and the value did not match it
            return false;
        }

        $dateTime = $className::createFromFormat($fractionalFormat, $fraction, $timeZone);

        if ($dateTime !== false || $fractionalFormat === $format) {
            return $dateTime;
        }

        // the dot does not start fractional seconds, and the types skipped the format because of it
        return $className::createFromFormat($format, $value, $timeZone);
    }

    private static function getFractionalFormat(string $format): string
    {
        if (str_contains($format, '.u')) {
            return $format;
        }

        return self::$fractionalFormats[$format] ??= preg_replace('/(?<!\\\\)s/', 's.u', $format, 1) ?? $format;
    }
}
