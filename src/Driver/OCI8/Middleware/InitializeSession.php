<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\OCI8\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class InitializeSession implements Middleware
{
    /**
     * @param bool $fractionalSeconds Whether timestamps are formatted with fractional seconds, which is required
     *                                to store and retrieve them with the precise date/time types.
     */
    public function __construct(private readonly bool $fractionalSeconds = false)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $fraction = $this->fractionalSeconds ? '.FF6' : '';

        return new class ($driver, $fraction) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $fraction)
            {
                parent::__construct($driver);
            }

            /**
             * {@inheritDoc}
             */
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): Connection {
                $connection = parent::connect($params);

                $connection->exec(
                    'ALTER SESSION SET'
                        . " NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'"
                        . " NLS_TIME_FORMAT = 'HH24:MI:SS'"
                        . " NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS" . $this->fraction . "'"
                        . " NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD HH24:MI:SS" . $this->fraction . " TZH:TZM'"
                        . " NLS_NUMERIC_CHARACTERS = '.,'",
                );

                return $connection;
            }
        };
    }
}
