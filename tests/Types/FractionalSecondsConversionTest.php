<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Types;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;
use function date_default_timezone_set;

/**
 * Values with fractional seconds, in the shape the databases return them from columns that allow fractions.
 */
final class FractionalSecondsConversionTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    #[DataProvider('valueProvider')]
    public function testConvertsValueWithFractionalSeconds(
        AbstractPlatform $platform,
        string $typeName,
        string $value,
        string $expected,
    ): void {
        $dateTime = Type::getType($typeName)->convertToPHPValue($value, $platform);

        self::assertInstanceOf(DateTimeInterface::class, $dateTime);
        self::assertSame($expected, $dateTime->format('Y-m-d H:i:s.u P'));
    }

    /** @return iterable<string, array{AbstractPlatform, string, string, string}> */
    public static function valueProvider(): iterable
    {
        $platforms = [
            'MySQL' => new MySQLPlatform(),
            'MariaDB' => new MariaDBPlatform(),
            'PostgreSQL' => new PostgreSQLPlatform(),
            'SQL Server' => new SQLServerPlatform(),
            'Oracle' => new OraclePlatform(),
            'Db2' => new DB2Platform(),
            'SQLite' => new SQLitePlatform(),
        ];

        $dateTimeTypes = [
            Types::DATETIME_MUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_UTC_MUTABLE,
            Types::DATETIME_UTC_IMMUTABLE,
        ];

        foreach ($platforms as $platformName => $platform) {
            foreach ($dateTimeTypes as $typeName) {
                yield $platformName . ', ' . $typeName . ', 6 digits' => [
                    $platform,
                    $typeName,
                    '2026-10-07 23:59:59.999999',
                    '2026-10-07 23:59:59.999999 +00:00',
                ];

                yield $platformName . ', ' . $typeName . ', 2 digits' => [
                    $platform,
                    $typeName,
                    '2026-10-07 23:59:59.25',
                    '2026-10-07 23:59:59.250000 +00:00',
                ];

                yield $platformName . ', ' . $typeName . ', 9 digits are truncated' => [
                    $platform,
                    $typeName,
                    '2026-10-07 23:59:59.123456789',
                    '2026-10-07 23:59:59.123456 +00:00',
                ];

                yield $platformName . ', ' . $typeName . ', no fraction' => [
                    $platform,
                    $typeName,
                    '2026-10-07 23:59:59',
                    '2026-10-07 23:59:59.000000 +00:00',
                ];
            }

            foreach ([Types::TIME_MUTABLE, Types::TIME_IMMUTABLE] as $typeName) {
                $datePrefix = $platform instanceof OraclePlatform ? '1900-01-01 ' : '';

                yield $platformName . ', ' . $typeName . ', 6 digits' => [
                    $platform,
                    $typeName,
                    $datePrefix . '10:00:00.123456',
                    '1970-01-01 10:00:00.123456 +00:00',
                ];

                yield $platformName . ', ' . $typeName . ', 2 digits' => [
                    $platform,
                    $typeName,
                    $datePrefix . '10:00:00.25',
                    '1970-01-01 10:00:00.250000 +00:00',
                ];

                yield $platformName . ', ' . $typeName . ', 7 digits are truncated' => [
                    $platform,
                    $typeName,
                    $datePrefix . '10:00:00.1234567',
                    '1970-01-01 10:00:00.123456 +00:00',
                ];
            }
        }

        $dateTimeTzValues = [
            'MySQL' => ['2026-10-07 23:59:59.123456', '2026-10-07 23:59:59.123456 +00:00'],
            'MariaDB' => ['2026-10-07 23:59:59.123456', '2026-10-07 23:59:59.123456 +00:00'],
            'PostgreSQL' => ['2026-10-07 23:59:59.25+02', '2026-10-07 23:59:59.250000 +02:00'],
            'SQL Server' => ['2026-10-07 23:59:59.1234567 +05:30', '2026-10-07 23:59:59.123456 +05:30'],
            'Oracle' => ['2026-10-07 23:59:59.123456789 +02:00', '2026-10-07 23:59:59.123456 +02:00'],
            'Db2' => ['2026-10-07 23:59:59.123456', '2026-10-07 23:59:59.123456 +00:00'],
            'SQLite' => ['2026-10-07 23:59:59.123456', '2026-10-07 23:59:59.123456 +00:00'],
        ];

        foreach ($dateTimeTzValues as $platformName => [$value, $expected]) {
            foreach ([Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE] as $typeName) {
                yield $platformName . ', ' . $typeName => [$platforms[$platformName], $typeName, $value, $expected];
            }
        }

        foreach ([Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE] as $typeName) {
            yield 'PostgreSQL, ' . $typeName . ', offset with minutes' => [
                $platforms['PostgreSQL'],
                $typeName,
                '2026-10-07 23:59:59.5+05:30',
                '2026-10-07 23:59:59.500000 +05:30',
            ];

            yield 'SQL Server, ' . $typeName . ', no fraction' => [
                $platforms['SQL Server'],
                $typeName,
                '2026-10-07 23:59:59 +02:00',
                '2026-10-07 23:59:59.000000 +02:00',
            ];
        }
    }
}
