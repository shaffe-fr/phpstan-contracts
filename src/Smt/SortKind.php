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
    /** A `list<T>`: a length and the elements at the keys 0 to length - 1. */
    case List;
    /** An array with the string keys of an `array{a: T, b: U}` type. */
    case Shape;
    /** The type of the `[]` literal before it is given a list type. */
    case EmptyList;
    /** The type of the `null` literal before it is given a nullable context. */
    case Null;
    /** The type of a `throw` expression, which never produces a value. */
    case Never;
}
