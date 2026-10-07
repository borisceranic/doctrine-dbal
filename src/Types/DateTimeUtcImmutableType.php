<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Throwable;

use function str_contains;

/**
 * Immutable variant of {@see DateTimeUtcType}.
 *
 * Values are converted to the UTC timezone before being persisted, so that no timezone
 * information needs to be stored in the database. When read back, the stored value is
 * always interpreted as being in the UTC timezone.
 */
class DateTimeUtcImmutableType extends Type implements PhpDateTimeMappingType
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

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(self::getUtc())
                ->format($platform->getDateTimeFormatString());
        }

        throw InvalidType::new(
            $value,
            static::class,
            ['null', DateTimeImmutable::class],
        );
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
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        $format = $platform->getDateTimeFormatString();

        // see DateTimeParser for why the format is tried here, and skipped for a fraction it does not account for
        $dateTime = str_contains($value, '.') && ! str_contains($format, '.u')
            ? false
            : DateTimeImmutable::createFromFormat($format, $value, self::getUtc());

        if ($dateTime === false) {
            $dateTime = DateTimeParser::parse(DateTimeImmutable::class, $format, $value, self::getUtc());
        }

        if ($dateTime !== false) {
            return $dateTime;
        }

        try {
            return new DateTimeImmutable($value, self::getUtc());
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
