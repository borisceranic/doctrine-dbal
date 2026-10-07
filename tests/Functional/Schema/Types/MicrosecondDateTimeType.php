<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Schema\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeType;

/**
 * A user-land type with a fixed fractional seconds precision, as commonly used before DBAL supported one.
 *
 * @link https://github.com/doctrine/dbal/issues/6631
 */
class MicrosecondDateTimeType extends DateTimeType
{
    public const NAME = 'datetime_microseconds';

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTypeDeclarationSQL(['precision' => 6] + $column);
    }
}
