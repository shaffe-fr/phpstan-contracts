<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Term;

/**
 * A safety condition that must hold wherever execution reaches `$pathCondition`.
 */
final readonly class Obligation
{
    public function __construct(
        public ObligationKind $kind,
        public int $line,
        public string $expression,
        public Term $pathCondition,
        public Term $goal,
        /**
         * The inlined calls the obligation comes from, outermost first, such as
         * `['Role::canManage()', 'Role::level()']`. For a precondition, the last one is
         * the method whose `#[Requires]` it is.
         *
         * @var list<string>
         */
        public array $calls = [],
        /**
         * For an unexpected throw, the condition under which the `#[Throws]` declarations
         * allow it, in words, or null when none of them declares its class.
         */
        public ?string $allowedWhere = null,
    ) {
    }
}
