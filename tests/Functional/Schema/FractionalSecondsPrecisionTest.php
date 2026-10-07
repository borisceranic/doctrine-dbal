<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Schema;

use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\Functional\Schema\Types\MicrosecondDateTimeType;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

use function array_change_key_case;
use function array_map;
use function implode;

use const CASE_LOWER;

class FractionalSecondsPrecisionTest extends FunctionalTestCase
{
    private const TABLE_NAME = 'fractional_seconds_precision';

    public static function setUpBeforeClass(): void
    {
        if (Type::hasType(MicrosecondDateTimeType::NAME)) {
            return;
        }

        Type::addType(MicrosecondDateTimeType::NAME, MicrosecondDateTimeType::class);
    }

    protected function setUp(): void
    {
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        self::markTestSkipped('SQLite does not have a fractional seconds precision.');
    }

    public function testIntrospectsPrecision(): void
    {
        $expected = [
            'dt0' => 0,
            'dt3' => 3,
            'dt6' => 6,
            'dtz' => 6,
        ];

        if ($this->supportsTimePrecision()) {
            $expected['tm'] = 6;
        }

        $this->dropAndCreateTable($this->createTable($expected));

        $schemaManager = $this->connection->createSchemaManager();

        self::assertSame($expected, $this->getPrecisions(
            $schemaManager->listTableColumns(self::TABLE_NAME),
        ));

        self::assertSame($expected, $this->getPrecisions(
            $schemaManager->introspectTableColumnsByUnquotedName(self::TABLE_NAME),
        ));
    }

    public function testNoDiffAfterCreation(): void
    {
        $table = $this->createTable([
            'dt0' => 0,
            'dt3' => 3,
            'dt6' => 6,
            'dtz' => 6,
        ] + ($this->supportsTimePrecision() ? ['tm' => 3] : []));

        $this->dropAndCreateTable($table);

        $this->assertNoDiff($table);
    }

    /**
     * A column widened outside of DBAL must not be narrowed by a mapping which does not specify a precision.
     */
    public function testUnspecifiedPrecisionAcceptsAnyPrecision(): void
    {
        $this->dropAndCreateTable($this->createTable(['dt6' => 6, 'dtz' => 6]));

        $this->assertNoDiff($this->createTable(['dt6' => null, 'dtz' => null]));
    }

    public function testDetectsPrecisionChange(): void
    {
        $this->dropAndCreateTable($this->createTable(['dt6' => 6]));

        $schemaManager = $this->connection->createSchemaManager();

        $narrowed = $this->createTable(['dt6' => 3]);

        $diff = $schemaManager->createComparator()
            ->compareTables($schemaManager->introspectTableByUnquotedName(self::TABLE_NAME), $narrowed);

        self::assertFalse($diff->isEmpty());

        $schemaManager->alterTable($diff);

        self::assertSame(['dt6' => 3], $this->getPrecisions(
            $schemaManager->introspectTableColumnsByUnquotedName(self::TABLE_NAME),
        ));

        $this->assertNoDiff($narrowed);
    }

    public function testNoDiffForCustomTypeWithFixedPrecision(): void
    {
        $table = Table::editor()
            ->setUnquotedName(self::TABLE_NAME)
            ->setColumns(
                Column::editor()
                    ->setUnquotedName('dt')
                    ->setTypeName(MicrosecondDateTimeType::NAME)
                    ->create(),
            )
            ->create();

        $this->dropAndCreateTable($table);

        $this->assertNoDiff($table);
    }

    /** @param array<non-empty-string, ?int> $precisions */
    private function createTable(array $precisions): Table
    {
        $columns = [];

        foreach ($precisions as $name => $precision) {
            $columns[] = Column::editor()
                ->setUnquotedName($name)
                ->setTypeName(match ($name) {
                    'dtz' => Types::DATETIMETZ_MUTABLE,
                    'tm' => Types::TIME_MUTABLE,
                    default => Types::DATETIME_MUTABLE,
                })
                ->setPrecision($precision)
                ->setNotNull(false)
                ->create();
        }

        return Table::editor()
            ->setUnquotedName(self::TABLE_NAME)
            ->setColumns(...$columns)
            ->create();
    }

    /**
     * @param array<Column> $columns
     *
     * @return array<string, ?int>
     */
    private function getPrecisions(array $columns): array
    {
        $precisions = [];

        foreach ($columns as $column) {
            $precisions[$column->getName()] = $column->getPrecision();
        }

        return array_change_key_case($precisions, CASE_LOWER);
    }

    private function assertNoDiff(Table $desired): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        $diff = $schemaManager->createComparator()
            ->compareTables($schemaManager->introspectTableByUnquotedName(self::TABLE_NAME), $desired);

        self::assertTrue($diff->isEmpty(), 'Tables should be identical, got changes to ' . implode(', ', array_map(
            static fn ($columnDiff): string => $columnDiff->getOldColumn()->getName(),
            $diff->getChangedColumns(),
        )));
    }

    private function supportsTimePrecision(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof DB2Platform;
    }
}
