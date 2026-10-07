<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

use function str_contains;

/**
 * Type that maps an SQL TIME to a PHP DateTime object.
 */
class TimeType extends Type implements PhpTimeMappingType
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getTimeTypeDeclarationSQL($column);
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
        if ($value === null) {
            return $value;
        }

        if ($value instanceof DateTime) {
            return $value->format($platform->getTimeFormatString());
        }

        throw InvalidType::new($value, static::class, ['null', DateTime::class]);
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
        if ($value === null || $value instanceof DateTime) {
            return $value;
        }

        $format = '!' . $platform->getTimeFormatString();

        // see DateTimeParser for why the format is tried here, and skipped for a fraction it does not account for
        $dateTime = str_contains($value, '.') && ! str_contains($format, '.u')
            ? false
            : DateTime::createFromFormat($format, $value);

        if ($dateTime === false) {
            $dateTime = DateTimeParser::parse(DateTime::class, $format, $value);
        }

        if ($dateTime !== false) {
            return $dateTime;
        }

        throw InvalidFormat::new(
            $value,
            static::class,
            $platform->getTimeFormatString(),
        );
    }
}
