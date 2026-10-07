<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Types;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\PhpTimeMappingType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function in_array;

final class PreciseTypesTest extends TestCase
{
    private const MUTABLE_TYPES = [
        Types::DATETIME_PRECISE_MUTABLE,
        Types::DATETIMETZ_PRECISE_MUTABLE,
        Types::TIME_PRECISE_MUTABLE,
    ];

    private const IMMUTABLE_TYPES = [
        Types::DATETIME_PRECISE_IMMUTABLE,
        Types::DATETIMETZ_PRECISE_IMMUTABLE,
        Types::TIME_PRECISE_IMMUTABLE,
    ];

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Zagreb');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    #[DataProvider('platformAndTypeProvider')]
    public function testRoundTripsMicroseconds(AbstractPlatform $platform, string $typeName): void
    {
        $type  = Type::getType($typeName);
        $value = $this->createValue($typeName, '2026-10-07 23:59:59.999999');

        $databaseValue = $type->convertToDatabaseValue($value, $platform);

        self::assertIsString($databaseValue);
        self::assertStringContainsString('59:59.999999', $databaseValue);

        $phpValue = $type->convertToPHPValue($databaseValue, $platform);

        self::assertInstanceOf($value::class, $phpValue);

        $format = $type instanceof PhpTimeMappingType ? 'H:i:s.u' : 'Y-m-d H:i:s.u P';
        self::assertSame($value->format($format), $phpValue->format($format));
    }

    #[DataProvider('platformAndTypeProvider')]
    public function testReadsValueWithoutFraction(AbstractPlatform $platform, string $typeName): void
    {
        $type  = Type::getType($typeName);
        $value = $this->createValue($typeName, '2026-10-07 23:59:59');

        $format = match ($typeName) {
            Types::DATETIME_PRECISE_MUTABLE,
            Types::DATETIME_PRECISE_IMMUTABLE => $platform->getDateTimeFormatString(),
            Types::DATETIMETZ_PRECISE_MUTABLE,
            Types::DATETIMETZ_PRECISE_IMMUTABLE => $platform->getDateTimeTzFormatString(),
            default => $platform->getTimeFormatString(),
        };

        $phpValue = $type->convertToPHPValue($value->format($format), $platform);

        self::assertInstanceOf(DateTimeInterface::class, $phpValue);
        self::assertSame('59:59.000000', $phpValue->format('i:s.u'));
    }

    #[DataProvider('platformAndTypeProvider')]
    public function testConvertsNull(AbstractPlatform $platform, string $typeName): void
    {
        $type = Type::getType($typeName);

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    #[DataProvider('platformAndTypeProvider')]
    public function testRejectsValueOfOtherMutability(AbstractPlatform $platform, string $typeName): void
    {
        $value = in_array($typeName, self::MUTABLE_TYPES, true) ? new DateTimeImmutable() : new DateTime();

        $this->expectException(ConversionException::class);

        Type::getType($typeName)->convertToDatabaseValue($value, $platform);
    }

    #[DataProvider('platformAndTypeProvider')]
    public function testRejectsInvalidValue(AbstractPlatform $platform, string $typeName): void
    {
        $this->expectException(ConversionException::class);

        Type::getType($typeName)->convertToPHPValue('not a date', $platform);
    }

    /** @return iterable<string, array{AbstractPlatform, string}> */
    public static function platformAndTypeProvider(): iterable
    {
        foreach (self::getPlatforms() as $platformName => $platform) {
            foreach ([...self::MUTABLE_TYPES, ...self::IMMUTABLE_TYPES] as $typeName) {
                yield $platformName . ', ' . $typeName => [$platform, $typeName];
            }
        }
    }

    #[DataProvider('declarationProvider')]
    public function testDeclaration(
        AbstractPlatform $platform,
        string $typeName,
        ?int $precision,
        string $expected,
    ): void {
        self::assertSame($expected, Type::getType($typeName)->getSQLDeclaration(
            ['precision' => $precision],
            $platform,
        ));
    }

    /** @return iterable<string, array{AbstractPlatform, string, ?int, string}> */
    public static function declarationProvider(): iterable
    {
        $expectations = [
            'MySQL' => [
                Types::DATETIME_PRECISE_MUTABLE => ['DATETIME(6)', 'DATETIME(3)'],
                Types::DATETIMETZ_PRECISE_MUTABLE => ['DATETIME(6)', 'DATETIME(3)'],
                Types::TIME_PRECISE_MUTABLE => ['TIME(6)', 'TIME(3)'],
            ],
            'PostgreSQL' => [
                Types::DATETIME_PRECISE_MUTABLE => ['TIMESTAMP(6) WITHOUT TIME ZONE', 'TIMESTAMP(3) WITHOUT TIME ZONE'],
                Types::DATETIMETZ_PRECISE_MUTABLE => ['TIMESTAMP(6) WITH TIME ZONE', 'TIMESTAMP(3) WITH TIME ZONE'],
                Types::TIME_PRECISE_MUTABLE => ['TIME(6) WITHOUT TIME ZONE', 'TIME(3) WITHOUT TIME ZONE'],
            ],
            'SQL Server' => [
                Types::DATETIME_PRECISE_MUTABLE => ['DATETIME2(6)', 'DATETIME2(3)'],
                Types::DATETIMETZ_PRECISE_MUTABLE => ['DATETIMEOFFSET(6)', 'DATETIMEOFFSET(3)'],
                Types::TIME_PRECISE_MUTABLE => ['TIME(6)', 'TIME(3)'],
            ],
            'Oracle' => [
                Types::DATETIME_PRECISE_MUTABLE => ['TIMESTAMP(6)', 'TIMESTAMP(3)'],
                Types::DATETIMETZ_PRECISE_MUTABLE => ['TIMESTAMP(6) WITH TIME ZONE', 'TIMESTAMP(3) WITH TIME ZONE'],
                Types::TIME_PRECISE_MUTABLE => ['TIMESTAMP(6)', 'TIMESTAMP(3)'],
            ],
            'Db2' => [
                Types::DATETIME_PRECISE_MUTABLE => ['TIMESTAMP(6)', 'TIMESTAMP(3)'],
                Types::DATETIMETZ_PRECISE_MUTABLE => ['TIMESTAMP(6)', 'TIMESTAMP(3)'],
                Types::TIME_PRECISE_MUTABLE => ['TIME', 'TIME'],
            ],
            'SQLite' => [
                Types::DATETIME_PRECISE_MUTABLE => ['DATETIME', 'DATETIME'],
                Types::DATETIMETZ_PRECISE_MUTABLE => ['DATETIME', 'DATETIME'],
                Types::TIME_PRECISE_MUTABLE => ['TIME', 'TIME'],
            ],
        ];

        $platforms = self::getPlatforms();

        foreach ($expectations as $platformName => $types) {
            foreach ($types as $typeName => [$default, $three]) {
                $name = $platformName . ', ' . $typeName;

                yield $name . ', default' => [$platforms[$platformName], $typeName, null, $default];
                yield $name . ', 3' => [$platforms[$platformName], $typeName, 3, $three];
            }
        }
    }

    #[DataProvider('comparisonProvider')]
    public function testComparisonWithIntrospectedColumn(
        string $typeName,
        ?int $precision,
        int $introspectedPrecision,
        bool $expected,
    ): void {
        $platform = new MySQLPlatform();

        $desired = Column::editor()
            ->setUnquotedName('col')
            ->setTypeName($typeName)
            ->setPrecision($precision)
            ->create();

        $introspected = Column::editor()
            ->setUnquotedName('col')
            ->setTypeName(Types::DATETIME_MUTABLE)
            ->setPrecision($introspectedPrecision)
            ->create();

        self::assertSame($expected, $platform->columnsEqual($introspected, $desired));
        self::assertSame($expected, $platform->columnsEqual($desired, $introspected));
    }

    /** @return iterable<string, array{string, ?int, int, bool}> */
    public static function comparisonProvider(): iterable
    {
        yield 'precise, default, whole seconds' => [Types::DATETIME_PRECISE_MUTABLE, null, 0, false];
        yield 'precise, default, microseconds' => [Types::DATETIME_PRECISE_MUTABLE, null, 6, true];
        yield 'precise, 3, microseconds' => [Types::DATETIME_PRECISE_MUTABLE, 3, 6, false];
        yield 'precise, 3, milliseconds' => [Types::DATETIME_PRECISE_MUTABLE, 3, 3, true];
        yield 'immutable precise, default, microseconds' => [Types::DATETIME_PRECISE_IMMUTABLE, null, 6, true];
        yield 'plain, unspecified, microseconds' => [Types::DATETIME_MUTABLE, null, 6, true];
    }

    /** @return array<string, AbstractPlatform> */
    private static function getPlatforms(): array
    {
        return [
            'MySQL' => new MySQLPlatform(),
            'MariaDB' => new MariaDBPlatform(),
            'PostgreSQL' => new PostgreSQLPlatform(),
            'SQL Server' => new SQLServerPlatform(),
            'Oracle' => new OraclePlatform(),
            'Db2' => new DB2Platform(),
            'SQLite' => new SQLitePlatform(),
        ];
    }

    private function createValue(string $typeName, string $value): DateTime|DateTimeImmutable
    {
        if (in_array($typeName, self::MUTABLE_TYPES, true)) {
            return new DateTime($value);
        }

        return new DateTimeImmutable($value);
    }
}
