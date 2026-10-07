<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Exception;

use function str_contains;

/**
 * Immutable type of {@see DateTimeType}.
 */
class DateTimeImmutableType extends Type implements PhpDateTimeMappingType
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

        if ($value instanceof DateTimeImmutable) {
            return $value->format($platform->getDateTimeFormatString());
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
            : DateTimeImmutable::createFromFormat($format, $value);

        if ($dateTime === false) {
            $dateTime = DateTimeParser::parse(DateTimeImmutable::class, $format, $value);
        }

        if ($dateTime !== false) {
            return $dateTime;
        }

        try {
            return new DateTimeImmutable($value);
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
