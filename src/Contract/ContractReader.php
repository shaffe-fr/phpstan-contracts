<?php

declare(strict_types=1);

namespace PhpstanContracts\Contract;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;
use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Error;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class ContractReader
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function read(ClassMethod|PropertyHook $method): MethodContract
    {
        $requires = [];
        $ensures = [];
        $pure = false;
        $throws = [];

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                // Class names are case-insensitive: PHP instantiates #[...\ENSURES] as Ensures.
                match (strtolower(ltrim($attribute->name->toString(), '\\'))) {
                    strtolower(Requires::class) => $requires[] = $this->clause($attribute),
                    strtolower(Ensures::class) => $ensures[] = $this->clause($attribute),
                    strtolower(Pure::class) => $pure = true,
                    strtolower(Throws::class) => $throws[] = $this->throwsClause($attribute),
                    default => null,
                };
            }
        }

        return new MethodContract($requires, $ensures, $pure, $throws);
    }

    private function throwsClause(Attribute $attribute): ThrowsClause
    {
        $line = $attribute->getStartLine();
        $class = null;
        $when = null;

        foreach ($attribute->args as $position => $argument) {
            $name = $argument->name?->toString() ?? ($position === 0 ? 'class' : ($position === 1 ? 'when' : null));
            if ($name === 'class') {
                $class = $argument->value;
            } elseif ($name === 'when') {
                $when = $argument->value;
            } else {
                throw new VerificationRejected('#[Throws] takes a class and an optional `when` condition.', $line, 'contracts.invalidContract');
            }
        }

        if (
            !$class instanceof ClassConstFetch
            || !$class->class instanceof Name
            || !$class->name instanceof Identifier
            || $class->name->toLowerString() !== 'class'
        ) {
            throw new VerificationRejected('#[Throws] needs the exception class as `Name::class`.', $line, 'contracts.invalidContract');
        }

        return new ThrowsClause(
            ltrim($class->class->toString(), '\\'),
            $when === null ? null : $this->parseClause($when, $line),
            $line,
        );
    }

    private function clause(Attribute $attribute): ContractClause
    {
        $line = $attribute->getStartLine();
        $argument = $attribute->args[0] ?? null;

        if ($argument === null) {
            throw new VerificationRejected(
                'A contract expression must be a plain string literal.',
                $line,
                'contracts.invalidContract',
            );
        }

        return $this->parseClause($argument->value, $line);
    }

    private function parseClause(\PhpParser\Node\Expr $value, int $line): ContractClause
    {
        if (!$value instanceof String_) {
            throw new VerificationRejected(
                'A contract expression must be a plain string literal.',
                $line,
                'contracts.invalidContract',
            );
        }

        $source = $value->value;

        try {
            $statements = $this->parser->parse('<?php ' . $source . ';');
        } catch (Error $error) {
            throw new VerificationRejected(
                sprintf('Contract expression `%s` is not valid PHP: %s', $source, $error->getRawMessage()),
                $line,
                'contracts.invalidContract',
            );
        }

        if ($statements === null || count($statements) !== 1 || !$statements[0] instanceof Expression) {
            throw new VerificationRejected(
                sprintf('Contract `%s` must be exactly one PHP expression.', $source),
                $line,
                'contracts.invalidContract',
            );
        }

        return new ContractClause($source, $statements[0]->expr, $line);
    }
}
