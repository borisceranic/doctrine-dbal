<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Platforms;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FractionalSecondsPrecisionTest extends TestCase
{
    /**
     * @param 'getDateTimeTypeDeclarationSQL'|'getDateTimeTzTypeDeclarationSQL'|'getTimeTypeDeclarationSQL' $method
     * @param array<string, mixed>                                                                          $column
     */
    #[DataProvider('declarationProvider')]
    public function testDeclaration(
        AbstractPlatform $platform,
        string $method,
        array $column,
        string $expected,
    ): void {
        self::assertSame($expected, match ($method) {
            'getDateTimeTypeDeclarationSQL' => $platform->getDateTimeTypeDeclarationSQL($column),
            'getDateTimeTzTypeDeclarationSQL' => $platform->getDateTimeTzTypeDeclarationSQL($column),
            'getTimeTypeDeclarationSQL' => $platform->getTimeTypeDeclarationSQL($column),
        });
    }

    /**
     * @return iterable<string, array{
     *     AbstractPlatform,
     *     'getDateTimeTypeDeclarationSQL'|'getDateTimeTzTypeDeclarationSQL'|'getTimeTypeDeclarationSQL',
     *     array<string, mixed>,
     *     string,
     * }>
     */
    public static function declarationProvider(): iterable
    {
        $expectations = [
            'MySQL' => [
                new MySQLPlatform(),
                ['DATETIME', 'DATETIME', 'DATETIME(3)'],
                ['DATETIME', 'DATETIME', 'DATETIME(3)'],
                ['TIME', 'TIME', 'TIME(3)'],
            ],
            'MariaDB' => [
                new MariaDBPlatform(),
                ['DATETIME', 'DATETIME', 'DATETIME(3)'],
                ['DATETIME', 'DATETIME', 'DATETIME(3)'],
                ['TIME', 'TIME', 'TIME(3)'],
            ],
            'PostgreSQL' => [
                new PostgreSQLPlatform(),
                ['TIMESTAMP(0) WITHOUT TIME ZONE', 'TIMESTAMP(0) WITHOUT TIME ZONE', 'TIMESTAMP(3) WITHOUT TIME ZONE'],
                ['TIMESTAMP(0) WITH TIME ZONE', 'TIMESTAMP(0) WITH TIME ZONE', 'TIMESTAMP(3) WITH TIME ZONE'],
                ['TIME(0) WITHOUT TIME ZONE', 'TIME(0) WITHOUT TIME ZONE', 'TIME(3) WITHOUT TIME ZONE'],
            ],
            'SQL Server' => [
                new SQLServerPlatform(),
                ['DATETIME2(6)', 'DATETIME2(0)', 'DATETIME2(3)'],
                ['DATETIMEOFFSET(6)', 'DATETIMEOFFSET(0)', 'DATETIMEOFFSET(3)'],
                ['TIME(0)', 'TIME(0)', 'TIME(3)'],
            ],
            'Oracle' => [
                new OraclePlatform(),
                ['TIMESTAMP(0)', 'TIMESTAMP(0)', 'TIMESTAMP(3)'],
                ['TIMESTAMP(0) WITH TIME ZONE', 'TIMESTAMP(0) WITH TIME ZONE', 'TIMESTAMP(3) WITH TIME ZONE'],
                ['DATE', 'DATE', 'TIMESTAMP(3)'],
            ],
            'Db2' => [
                new DB2Platform(),
                ['TIMESTAMP(0)', 'TIMESTAMP(0)', 'TIMESTAMP(3)'],
                ['TIMESTAMP(0)', 'TIMESTAMP(0)', 'TIMESTAMP(3)'],
                ['TIME', 'TIME', 'TIME'],
            ],
            'SQLite' => [
                new SQLitePlatform(),
                ['DATETIME', 'DATETIME', 'DATETIME'],
                ['DATETIME', 'DATETIME', 'DATETIME'],
                ['TIME', 'TIME', 'TIME'],
            ],
        ];

        $methods = [
            1 => 'getDateTimeTypeDeclarationSQL',
            2 => 'getDateTimeTzTypeDeclarationSQL',
            3 => 'getTimeTypeDeclarationSQL',
        ];

        foreach ($expectations as $platformName => $expectation) {
            $platform = $expectation[0];

            foreach ($methods as $i => $method) {
                [$unspecified, $zero, $three] = $expectation[$i];

                $name = $platformName . ', ' . $method;

                yield $name . ', unspecified' => [$platform, $method, [], $unspecified];
                yield $name . ', null' => [$platform, $method, ['precision' => null], $unspecified];
                yield $name . ', 0' => [$platform, $method, ['precision' => 0], $zero];
                yield $name . ', 3' => [$platform, $method, ['precision' => 3], $three];
            }
        }
    }

    #[DataProvider('comparisonProvider')]
    public function testComparison(
        AbstractPlatform $platform,
        string $typeName1,
        ?int $precision1,
        string $typeName2,
        ?int $precision2,
        bool $expected,
    ): void {
        $column1 = self::createColumn($typeName1, $precision1);
        $column2 = self::createColumn($typeName2, $precision2);

        self::assertSame($expected, $platform->columnsEqual($column1, $column2));
        self::assertSame($expected, $platform->columnsEqual($column2, $column1));
    }

    /** @return iterable<string, array{AbstractPlatform, string, ?int, string, ?int, bool}> */
    public static function comparisonProvider(): iterable
    {
        foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLServerPlatform()] as $platform) {
            $name = $platform::class;

            yield $name . ', unspecified accepts zero' => [
                $platform,
                Types::DATETIME_MUTABLE,
                null,
                Types::DATETIME_MUTABLE,
                0,
                true,
            ];

            yield $name . ', unspecified accepts microseconds' => [
                $platform,
                Types::DATETIME_MUTABLE,
                null,
                Types::DATETIME_MUTABLE,
                6,
                true,
            ];

            yield $name . ', unspecified accepts microseconds for an immutable type' => [
                $platform,
                Types::DATETIME_IMMUTABLE,
                null,
                Types::DATETIME_MUTABLE,
                6,
                true,
            ];

            yield $name . ', unspecified accepts microseconds for a time type' => [
                $platform,
                Types::TIME_MUTABLE,
                null,
                Types::TIME_MUTABLE,
                6,
                true,
            ];

            yield $name . ', same precision' => [
                $platform,
                Types::DATETIMETZ_MUTABLE,
                3,
                Types::DATETIMETZ_MUTABLE,
                3,
                true,
            ];

            yield $name . ', different precision' => [
                $platform,
                Types::DATETIME_MUTABLE,
                3,
                Types::DATETIME_MUTABLE,
                6,
                false,
            ];

            yield $name . ', different type' => [
                $platform,
                Types::TIME_MUTABLE,
                null,
                Types::DATETIME_MUTABLE,
                6,
                false,
            ];

            yield $name . ', different decimal precision' => [
                $platform,
                Types::DECIMAL,
                10,
                Types::DECIMAL,
                12,
                false,
            ];
        }
    }

    private static function createColumn(string $typeName, ?int $precision): Column
    {
        return Column::editor()
            ->setUnquotedName('col')
            ->setTypeName($typeName)
            ->setPrecision($precision)
            ->create();
    }
}
