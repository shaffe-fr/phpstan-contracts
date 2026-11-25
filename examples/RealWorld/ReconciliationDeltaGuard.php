<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * A service without any property: its methods call each other through `$this`, which
 * has no state to model, so each call is translated with the body of the helper.
 */
final class ReconciliationDeltaGuard
{
    private const int TOLERANCE_CENTS = 100;

    /**
     * Whether a ledger line and a bank line are close enough to be matched automatically.
     *
     * @param int<0, 100000000> $ledgerCents
     * @param int<0, 100000000> $bankCents
     */
    #[Ensures('implies($ledgerCents === $bankCents, $result)')]
    #[Ensures('implies($result, abs($ledgerCents - $bankCents) <= 100)')]
    #[Ensures('implies(abs($ledgerCents - $bankCents) <= 100, $result)')]
    public function isMatched(int $ledgerCents, int $bankCents): bool
    {
        return $this->withinTolerance($this->deltaCents($ledgerCents, $bankCents));
    }

    /**
     * Same rule, but the delta keeps its sign: a bank line far below the ledger line has
     * a very negative delta, which is "within tolerance".
     *
     * @param int<0, 100000000> $ledgerCents
     * @param int<0, 100000000> $bankCents
     */
    #[Ensures('implies($ledgerCents === $bankCents, $result)')]
    #[Ensures('implies($result, abs($ledgerCents - $bankCents) <= 100)')]
    public function isMatchedBroken(int $ledgerCents, int $bankCents): bool
    {
        return $this->withinTolerance($ledgerCents - $bankCents);
    }

    private function deltaCents(int $ledgerCents, int $bankCents): int
    {
        return abs($ledgerCents - $bankCents);
    }

    private function withinTolerance(int $deltaCents): bool
    {
        return $deltaCents <= self::TOLERANCE_CENTS;
    }
}
