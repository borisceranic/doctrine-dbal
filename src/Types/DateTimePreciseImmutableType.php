<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;

use function is_string;

/**
 * Immutable type of {@see DateTimePreciseType}.
 *
 * The fractional seconds precision of the column defaults to microseconds.
 */
class DateTimePreciseImmutableType extends DateTimeImmutableType implements FractionalSecondsType
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
        if ($value instanceof DateTimeImmutable) {
            return $value->format($platform->getDateTimePreciseFormatString());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * @param T $value
     *
     * @return (T is null ? null : DateTimeImmutable)
     *
     * @template T
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if (is_string($value)) {
            // the precise formats account for fractions, so the format is tried first, see DateTimeParser
            $format   = $platform->getDateTimePreciseFormatString();
            $dateTime = DateTimeImmutable::createFromFormat($format, $value);

            if ($dateTime === false) {
                $dateTime = DateTimeParser::parse(DateTimeImmutable::class, $format, $value);
            }

            if ($dateTime !== false) {
                return $dateTime;
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
