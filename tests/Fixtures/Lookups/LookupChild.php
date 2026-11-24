<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Lookups;

/**
 * @phpstan-import-type Percent from LookupAliases
 */
final class LookupChild extends LookupBase implements LookupMarker
{
    use LookupStamps;
}
