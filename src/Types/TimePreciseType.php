<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;

use function is_string;

/**
 * Type that maps an SQL TIME with fractional seconds to a PHP DateTime object.
 *
 * The fractional seconds precision of the column defaults to microseconds.
 */
class TimePreciseType extends TimeType implements FractionalSecondsType
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['precision'] ??= self::DEFAULT_PRECISION;

        return parent::getSQLDeclaration($column, $platform);
    }

    /**
     * @param T $value
     *
     * @return (T is null ? null : string)
     *
     * @template T
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTime) {
            return $value->format($platform->getTimePreciseFormatString());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * @param T $value
     *
     * @return (T is null ? null : DateTime)
     *
     * @template T
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTime
    {
        if (is_string($value)) {
            // the precise formats account for fractions, so the format is tried first, see DateTimeParser
            $format   = '!' . $platform->getTimePreciseFormatString();
            $dateTime = DateTime::createFromFormat($format, $value);

            if ($dateTime === false) {
                $dateTime = DateTimeParser::parse(DateTime::class, $format, $value);
            }

            if ($dateTime !== false) {
                return $dateTime;
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
