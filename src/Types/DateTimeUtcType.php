<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Throwable;

use function str_contains;

/**
 * Type that maps an SQL DATETIME/TIMESTAMP to a PHP DateTime object and stores it in UTC.
 *
 * Values are converted to the UTC timezone before being persisted, so that no timezone
 * information needs to be stored in the database. When read back, the stored value is
 * always interpreted as being in the UTC timezone.
 */
class DateTimeUtcType extends Type implements PhpDateTimeMappingType
{
    private static ?DateTimeZone $utc = null;

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
            return (clone $value)->setTimezone(self::getUtc())
                ->format($platform->getDateTimeFormatString());
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
            : DateTime::createFromFormat($format, $value, self::getUtc());

        if ($dateTime === false) {
            $dateTime = DateTimeParser::parse(DateTime::class, $format, $value, self::getUtc());
        }

        if ($dateTime !== false) {
            return $dateTime;
        }

        try {
            return new DateTime($value, self::getUtc());
        } catch (Throwable $e) {
            throw InvalidFormat::new(
                $value,
                static::class,
                $platform->getDateTimeFormatString(),
                $e,
            );
        }
    }

    private static function getUtc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }
}
