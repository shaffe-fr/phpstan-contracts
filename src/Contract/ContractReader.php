<?php

declare(strict_types=1);

namespace PhpstanContracts\Contract;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Error;
use PhpParser\Node\Attribute;
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

    public function read(ClassMethod $method): MethodContract
    {
        $requires = [];
        $ensures = [];
        $pure = false;

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                match (ltrim($attribute->name->toString(), '\\')) {
                    Requires::class => $requires[] = $this->clause($attribute),
                    Ensures::class => $ensures[] = $this->clause($attribute),
                    Pure::class => $pure = true,
                    default => null,
                };
            }
        }

        return new MethodContract($requires, $ensures, $pure);
    }

    private function clause(Attribute $attribute): ContractClause
    {
        $line = $attribute->getStartLine();
        $argument = $attribute->args[0] ?? null;

        if ($argument === null || !$argument->value instanceof String_) {
            throw new VerificationRejected(
                'A contract expression must be a plain string literal.',
                $line,
                'contracts.invalidContract',
            );
        }

        $source = $argument->value->value;

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
