<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Types;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\DateTimeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateTimeParserTest extends TestCase
{
    #[DataProvider('validValueProvider')]
    public function testParsesValidValue(string $format, string $value, string $expected): void
    {
        $dateTime = DateTimeParser::parse(DateTimeImmutable::class, $format, $value, new DateTimeZone('UTC'));

        self::assertInstanceOf(DateTimeImmutable::class, $dateTime);
        self::assertSame($expected, $dateTime->format('Y-m-d H:i:s.u P'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validValueProvider(): iterable
    {
        yield 'fraction added' => ['Y-m-d H:i:s', '2026-10-07 10:00:00.5', '2026-10-07 10:00:00.500000 +00:00'];
        yield 'fraction removed' => ['Y-m-d H:i:s.u', '2026-10-07 10:00:00', '2026-10-07 10:00:00.000000 +00:00'];
        yield 'excess digits' => ['Y-m-d H:i:s.u', '2026-10-07 10:00:00.9999999', '2026-10-07 10:00:00.999999 +00:00'];
        yield 'offset' => ['Y-m-d H:i:sO', '2026-10-07 10:00:00.5-03', '2026-10-07 10:00:00.500000 -03:00'];
        yield 'escaped s' => ['\s Y-m-d H:i:s', 's 2026-10-07 10:00:00.5', '2026-10-07 10:00:00.500000 +00:00'];
        yield 'reset' => ['!H:i:s', '10:00:00.5', '1970-01-01 10:00:00.500000 +00:00'];
        yield 'dot in the format' => ['d.m.Y H:i:s', '07.10.2026 10:00:00', '2026-10-07 10:00:00.000000 +00:00'];
        yield 'dot in the format and a fraction' => [
            'd.m.Y H:i:s',
            '07.10.2026 10:00:00.5',
            '2026-10-07 10:00:00.500000 +00:00',
        ];

        yield 'fraction and offset' => [
            'Y-m-d H:i:sO',
            '2026-10-07 10:00:00.1234567+05:30',
            '2026-10-07 10:00:00.123456 +05:30',
        ];
    }

    #[DataProvider('invalidValueProvider')]
    public function testRejectsInvalidValue(string $format, string $value): void
    {
        self::assertFalse(DateTimeParser::parse(DateTime::class, $format, $value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidValueProvider(): iterable
    {
        yield 'garbage' => ['Y-m-d H:i:s', 'abcdefg'];

        // the types try the format themselves, the parser only handles what the format cannot
        yield 'no fraction for a format without fraction' => ['Y-m-d H:i:s', '2026-10-07 10:00:00'];
        yield 'a fraction the format accounts for' => ['Y-m-d H:i:s.u', '2026-10-07 10:00:00.5'];
        yield 'garbage after the fraction' => ['Y-m-d H:i:s', '2026-10-07 10:00:00.5 foo'];
        yield 'fraction without digits' => ['Y-m-d H:i:s', '2026-10-07 10:00:00.'];
        yield 'date only' => ['Y-m-d H:i:s', '2026-10-07'];
    }
}
