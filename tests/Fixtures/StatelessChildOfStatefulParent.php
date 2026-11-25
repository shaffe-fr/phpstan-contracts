<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * The private property of the parent is state of every instance.
 */
final class StatelessChildOfStatefulParent extends StatelessParentWithState
{
    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result >= 0')]
    public function callsAHelper(int $amount): int
    {
        return $this->helper($amount);
    }

    private function helper(int $amount): int
    {
        return $amount;
    }
}
