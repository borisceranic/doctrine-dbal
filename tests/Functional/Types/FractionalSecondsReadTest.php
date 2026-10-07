<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Types;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Columns that allow fractional seconds may return them even to types that do not write them,
 * e.g. hand-widened columns or values written by the database itself.
 */
class FractionalSecondsReadTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $definitions = ['DATETIME(6)', 'DATETIME(6)', 'TIME(6)'];
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $definitions = [
                'TIMESTAMP(6) WITHOUT TIME ZONE',
                'TIMESTAMP(6) WITH TIME ZONE',
                'TIME(6) WITHOUT TIME ZONE',
            ];
        } elseif ($platform instanceof SQLServerPlatform) {
            $definitions = ['DATETIME2(7)', 'DATETIMEOFFSET(7)', 'TIME(7)'];
        } elseif ($platform instanceof SqlitePlatform) {
            $definitions = ['DATETIME', 'DATETIME', 'TIME'];
        } else {
            self::markTestSkipped('The session date formats of this platform do not return fractional seconds.');
        }

        $table = new Table('fractional_seconds_read');

        foreach (['dt', 'dtz', 'tm'] as $i => $name) {
            $table->addColumn($name, Types::STRING, [
                'columnDefinition' => $definitions[$i],
                'notnull' => false,
            ]);
        }

        $this->dropAndCreateTable($table);
    }

    /** @dataProvider typeProvider */
    public function testReadsFractionalSeconds(string $column, string $typeName, string $value, string $expected): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->insert('fractional_seconds_read', [$column => $value]);

        $dateTime = Type::getType($typeName)->convertToPHPValue(
            $this->connection->fetchOne('SELECT ' . $column . ' FROM fractional_seconds_read'),
            $platform,
        );

        self::assertInstanceOf(DateTimeInterface::class, $dateTime);
        self::assertSame($expected, $dateTime->format('H:i:s.u'));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function typeProvider(): iterable
    {
        foreach ([Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE] as $typeName) {
            yield $typeName => ['dt', $typeName, '2026-10-07 23:59:59.123456', '23:59:59.123456'];
            yield $typeName . ', trailing zeros' => ['dt', $typeName, '2026-10-07 23:59:59.25', '23:59:59.250000'];
        }

        foreach ([Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE] as $typeName) {
            yield $typeName => ['dtz', $typeName, '2026-10-07 23:59:59.123456', '23:59:59.123456'];
            yield $typeName . ', no fraction' => ['dtz', $typeName, '2026-10-07 23:59:59', '23:59:59.000000'];
        }

        foreach ([Types::TIME_MUTABLE, Types::TIME_IMMUTABLE] as $typeName) {
            yield $typeName => ['tm', $typeName, '10:00:00.123456', '10:00:00.123456'];
            yield $typeName . ', trailing zeros' => ['tm', $typeName, '10:00:00.25', '10:00:00.250000'];
        }
    }
}
