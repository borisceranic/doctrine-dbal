<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Exception;

use function str_contains;

/**
 * Type that maps an SQL DATETIME/TIMESTAMP to a PHP DateTime object.
 */
class DateTimeType extends Type implements PhpDateTimeMappingType
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTypeDeclarationSQL($column);
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
            return $value->format($platform->getDateTimeFormatString());
        }

        throw InvalidType::new(
            $value,
            static::class,
            ['null', DateTime::class],
        );
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

        $format = $platform->getDateTimeFormatString();

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

        try {
            return new DateTime($value);
        } catch (Exception $e) {
            throw InvalidFormat::new(
                $value,
                static::class,
                $platform->getDateTimeFormatString(),
                $e,
            );
        }
    }
}
