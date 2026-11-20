<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

enum SortKind
{
    case Int;
    case Bool;
    case Float;
    case String;
    case Enum;
    case Nullable;
    case Record;
    /** The type of the `null` literal before it is given a nullable context. */
    case Null;
    /** The type of a `throw` expression, which never produces a value. */
    case Never;
}
