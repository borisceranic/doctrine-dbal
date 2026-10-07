<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Benchmarks\FractionalSeconds;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\TimeImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\VarDateTimeImmutableType;
use PhpBench\Attributes as Bench;

/**
 * Converts database values to PHP with the date/time types, as hydration does for every row.
 *
 * Throwing cases are measured including the exception, because that is what the caller pays.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(20000)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('microseconds', precision: 3)]
#[Bench\RetryThreshold(5.0)]
final class TemporalConversionBench
{
    private Type $type;

    private AbstractPlatform $platform;

    private string $value;

    /** @param array{type: class-string<Type>, platform: class-string<AbstractPlatform>, value: string} $params */
    public function setUp(array $params): void
    {
        $this->type     = new $params['type']();
        $this->platform = new $params['platform']();
        $this->value    = $params['value'];
    }

    /** @param array{type: class-string<Type>, platform: class-string<AbstractPlatform>, value: string} $params */
    #[Bench\ParamProviders('provideValues')]
    public function benchConvertToPHPValue(array $params): void
    {
        try {
            $this->type->convertToPHPValue($this->value, $this->platform);
        } catch (ConversionException) {
        }
    }

    /** @return iterable<string, array{type: class-string<Type>, platform: class-string<AbstractPlatform>, value: string}> */
    public function provideValues(): iterable
    {
        // values in the platform format: the hot path, which must not get slower
        yield 'datetime, MySQL, whole seconds' => [
            'type' => DateTimeImmutableType::class,
            'platform' => MySQLPlatform::class,
            'value' => '2026-10-07 23:59:59',
        ];

        yield 'datetimetz, PostgreSQL, whole seconds' => [
            'type' => DateTimeTzImmutableType::class,
            'platform' => PostgreSQLPlatform::class,
            'value' => '2026-10-07 23:59:59+02',
        ];

        yield 'time, PostgreSQL, whole seconds' => [
            'type' => TimeImmutableType::class,
            'platform' => PostgreSQLPlatform::class,
            'value' => '10:00:00',
        ];

        yield 'datetime, SQL Server, platform format with fraction' => [
            'type' => DateTimeImmutableType::class,
            'platform' => SQLServerPlatform::class,
            'value' => '2026-10-07 23:59:59.123456',
        ];

        // values with fractional seconds the platform format does not account for
        yield 'datetime, MySQL, microseconds' => [
            'type' => DateTimeImmutableType::class,
            'platform' => MySQLPlatform::class,
            'value' => '2026-10-07 23:59:59.123456',
        ];

        yield 'datetimetz, PostgreSQL, microseconds' => [
            'type' => DateTimeTzImmutableType::class,
            'platform' => PostgreSQLPlatform::class,
            'value' => '2026-10-07 23:59:59.123456+02',
        ];

        yield 'time, PostgreSQL, microseconds' => [
            'type' => TimeImmutableType::class,
            'platform' => PostgreSQLPlatform::class,
            'value' => '10:00:00.123456',
        ];

        yield 'time, SQL Server, 7 digits' => [
            'type' => TimeImmutableType::class,
            'platform' => SQLServerPlatform::class,
            'value' => '10:00:00.1234567',
        ];

        // the workaround the documentation used to recommend for fractional seconds, for reference
        yield 'vardatetime, MySQL, microseconds' => [
            'type' => VarDateTimeImmutableType::class,
            'platform' => MySQLPlatform::class,
            'value' => '2026-10-07 23:59:59.123456',
        ];

        // invalid values throw in both versions
        yield 'datetimetz, PostgreSQL, invalid' => [
            'type' => DateTimeTzImmutableType::class,
            'platform' => PostgreSQLPlatform::class,
            'value' => 'not a date',
        ];
    }
}
