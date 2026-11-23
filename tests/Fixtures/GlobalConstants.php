<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

// Global constants read by GlobalConstantInitializers. Nothing loads this file at runtime:
// it is in the autoload-dev classmap of composer.json, where PHPStan finds the constants
// and reads them from the file, as for the files of an analysed project. Composer only
// autoloads classes from a classmap, and there is none here.

const PRINTED = 'v' . (0.1 + 0.2);

const PRINTS_SHORT = ('v' . 0.1) === 'v0.1';

const ORDERS_A_PRINTED_FLOAT = (0.1 + 0.2) < '0.3!';

const MASKS_A_FLOAT = 1.5 | 0;

const SEVEN = 7;

const NUMBERED_PREFIX = 'v' . SEVEN;

define('PRINTED_BY_DEFINE', 'v' . (0.1 + 0.2));
