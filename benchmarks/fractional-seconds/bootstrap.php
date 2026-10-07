<?php

declare(strict_types=1);

// The benchmarked doctrine/dbal checkout is chosen at run time, so that the same subjects run against both.
$dbalDir = getenv('DBAL_DIR');

if ($dbalDir === false || ! is_file($dbalDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "Set DBAL_DIR to a doctrine/dbal checkout with installed dependencies.\n");
    exit(1);
}

require $dbalDir . '/vendor/autoload.php';
