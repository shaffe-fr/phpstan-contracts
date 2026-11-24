<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

/**
 * What a `loopBound` obligation says about the loop it belongs to: the iterations the proof
 * unrolls, the list the loop visits, and where the bound comes from.
 */
final readonly class LoopBound
{
    public function __construct(
        public int $iterations,
        /** The iterated expression as written, such as `$lines`. */
        public string $subject,
        /** The `#[Requires]` that gave the bound, or null for `contracts.loopUnroll`. */
        public ?string $origin,
        /** Whether a `#[Requires]` can name the list: a parameter, or a property of one. */
        public bool $nameable,
    ) {
    }
}
