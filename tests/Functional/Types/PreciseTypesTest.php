<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Types;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_contains;

class PreciseTypesTest extends FunctionalTestCase
{
    private const TABLE_NAME = 'precise_types';

    #[DataProvider('typeProvider')]
    public function testRoundTripsMicroseconds(string $typeName, string $format): void
    {
        $this->skipUnsupportedType($typeName);

        $this->dropAndCreateTable($this->createTable($typeName));

        $value = str_contains($typeName, 'immutable')
            ? new DateTimeImmutable('2026-10-07 23:59:59.999999')
            : new DateTime('2026-10-07 23:59:59.999999');

        $this->connection->insert(self::TABLE_NAME, ['val' => $value], ['val' => $typeName]);

        $result = Type::getType($typeName)->convertToPHPValue(
            $this->connection->fetchOne('SELECT val FROM ' . self::TABLE_NAME),
            $this->connection->getDatabasePlatform(),
        );

        self::assertInstanceOf($value::class, $result);
        self::assertSame($value->format($format), $result->format($format));
    }

    #[DataProvider('typeProvider')]
    public function testNoDiffAfterCreation(string $typeName): void
    {
        $this->skipUnsupportedType($typeName);

        foreach ([null, 0, 3, 6] as $precision) {
            $table = $this->createTable($typeName, $precision);

            $this->dropAndCreateTable($table);

            self::assertTrue(
                $this->compareWith($table)->isEmpty(),
                'Precision ' . ($precision ?? 'null') . ' should produce no diff.',
            );
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function typeProvider(): iterable
    {
        yield Types::DATETIME_PRECISE_MUTABLE => [Types::DATETIME_PRECISE_MUTABLE, 'Y-m-d H:i:s.u'];
        yield Types::DATETIME_PRECISE_IMMUTABLE => [Types::DATETIME_PRECISE_IMMUTABLE, 'Y-m-d H:i:s.u'];
        yield Types::DATETIMETZ_PRECISE_MUTABLE => [Types::DATETIMETZ_PRECISE_MUTABLE, 'U.u'];
        yield Types::DATETIMETZ_PRECISE_IMMUTABLE => [Types::DATETIMETZ_PRECISE_IMMUTABLE, 'U.u'];
        yield Types::TIME_PRECISE_MUTABLE => [Types::TIME_PRECISE_MUTABLE, 'H:i:s.u'];
        yield Types::TIME_PRECISE_IMMUTABLE => [Types::TIME_PRECISE_IMMUTABLE, 'H:i:s.u'];
    }

    public function testMigratesFromPlainType(): void
    {
        $this->dropAndCreateTable($this->createTable(Types::DATETIME_MUTABLE));

        $this->connection->insert(
            self::TABLE_NAME,
            ['val' => new DateTime('2026-10-07 23:59:59')],
            ['val' => Types::DATETIME_MUTABLE],
        );

        $precise = $this->createTable(Types::DATETIME_PRECISE_MUTABLE);
        $diff    = $this->compareWith($precise);

        $platform = $this->connection->getDatabasePlatform();

        $declaration = $platform->getDateTimeTypeDeclarationSQL([]);

        if ($declaration === $platform->getDateTimeTypeDeclarationSQL(['precision' => 6])) {
            // the platform has no fractional seconds precision or already uses microseconds
            self::assertTrue($diff->isEmpty());
        } else {
            self::assertFalse($diff->isEmpty());

            $this->connection->createSchemaManager()->alterTable($diff);

            self::assertTrue($this->compareWith($precise)->isEmpty());
        }

        $this->connection->update(
            self::TABLE_NAME,
            ['val' => new DateTime('2026-10-07 23:59:59.999999')],
            [],
            ['val' => Types::DATETIME_PRECISE_MUTABLE],
        );

        $result = Type::getType(Types::DATETIME_PRECISE_MUTABLE)->convertToPHPValue(
            $this->connection->fetchOne('SELECT val FROM ' . self::TABLE_NAME),
            $this->connection->getDatabasePlatform(),
        );

        self::assertInstanceOf(DateTimeInterface::class, $result);
        self::assertSame('2026-10-07 23:59:59.999999', $result->format('Y-m-d H:i:s.u'));
    }

    private function createTable(string $typeName, ?int $precision = null): Table
    {
        return Table::editor()
            ->setUnquotedName(self::TABLE_NAME)
            ->setColumns(
                Column::editor()
                    ->setUnquotedName('val')
                    ->setTypeName($typeName)
                    ->setPrecision($precision)
                    ->setNotNull(false)
                    ->create(),
            )
            ->create();
    }

    private function compareWith(Table $table): TableDiff
    {
        $schemaManager = $this->connection->createSchemaManager();

        return $schemaManager->createComparator()->compareTables(
            $schemaManager->introspectTableByUnquotedName(self::TABLE_NAME),
            $table,
        );
    }

    private function skipUnsupportedType(string $typeName): void
    {
        if (
            ! $this->connection->getDatabasePlatform() instanceof DB2Platform
            || ($typeName !== Types::TIME_PRECISE_MUTABLE && $typeName !== Types::TIME_PRECISE_IMMUTABLE)
        ) {
            return;
        }

        self::markTestSkipped('Db2 TIME columns do not support fractional seconds.');
    }
}
