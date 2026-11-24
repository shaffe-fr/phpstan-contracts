<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

enum ObligationKind: string
{
    case Overflow = 'overflow';
    case DivisionByZero = 'divisionByZero';
    case UnhandledMatch = 'unhandledMatch';
    case MissingReturn = 'missingReturn';
    case InvalidEnumValue = 'invalidEnumValue';
    case InvalidCast = 'invalidCast';
    case ReturnType = 'returnType';
    case RequiresViolated = 'requiresViolated';
    case UnexpectedThrow = 'unexpectedThrow';
    case UndefinedKey = 'undefinedKey';
    case LoopBound = 'loopBound';
}
