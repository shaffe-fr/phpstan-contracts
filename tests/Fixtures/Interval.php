<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * `$this` in the methods of a readonly data class.
 */
final readonly class Interval
{
    public function __construct(
        /** @var int<0, 1000000> */
        public int $start,
        /** @var int<0, 1000000> */
        public int $end,
        /** @var int<1, 1000>|null */
        public ?int $step,
    ) {
    }

    #[Requires('$this->start <= $this->end')]
    #[Ensures('$result >= 0')]
    #[Ensures('$result === $this->end - $this->start')]
    public function length(): int
    {
        return $this->end - $this->start;
    }

    #[Ensures('implies($result, $value >= $this->start && $value < $this->end)')]
    public function contains(int $value): bool
    {
        return $value >= $this->start && $value < $this->end;
    }

    #[Ensures('$result >= 1')]
    #[Ensures('implies($this->step !== null, $result === $this->step)')]
    public function stepOrOne(): int
    {
        if ($this->step !== null) {
            return $this->step;
        }

        return 1;
    }

    /** @param int<0, 1000000> $end */
    #[Ensures('$result->start === $this->start && $result->end === $end')]
    public function withEnd(int $end): self
    {
        return new self($this->start, $end, $this->step);
    }

    #[Ensures('$result >= 0')]
    public function lengthBroken(): int
    {
        return $this->end - $this->start;
    }

    #[Pure]
    public function methodCallOnThis(): int
    {
        return $this->length();
    }
}
