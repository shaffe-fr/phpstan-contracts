<?php

declare(strict_types=1);

namespace PhpstanContracts\Rule;

use PhpstanContracts\Contract\ContractReader;
use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InPropertyHookNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * PHP accepts the contract attributes on a property hook, which is a method to it, but
 * hooks are not verified: a contract there would read as proven when it is not.
 *
 * @implements Rule<InPropertyHookNode>
 */
final class PropertyHookContractRule implements Rule
{
    public function __construct(private readonly ContractReader $contracts)
    {
    }

    public function getNodeType(): string
    {
        return InPropertyHookNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $hook = $node->getOriginalNode();

        try {
            $contract = $this->contracts->read($hook);
        } catch (VerificationRejected $rejected) {
            return [RuleErrorBuilder::message($rejected->getMessage())->identifier($rejected->identifier)->line($rejected->errorLine)->build()];
        }

        if ($contract->isEmpty()) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Contracts on property hooks are not verified: move the logic to a method and put the contract there.')
                ->identifier('contracts.unsupported')
                ->line($hook->getStartLine())
                ->build(),
        ];
    }
}
