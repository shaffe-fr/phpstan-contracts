<?php

declare(strict_types=1);

namespace PhpstanContracts\Rule;

use PhpstanContracts\Contract\ContractReader;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Report\CounterexampleFormatter;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\MethodEncoder;
use PhpstanContracts\Translate\Obligation;
use PhpstanContracts\Translate\ObligationKind;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Proves the contracts of annotated methods with Z3 and reports a concrete
 * counterexample when a proof fails.
 *
 * @implements Rule<InClassMethodNode>
 */
final class VerifyContractRule implements Rule
{
    public function __construct(
        private readonly ContractReader $contracts,
        private readonly MethodEncoder $encoder,
        private readonly ScriptRenderer $renderer,
        private readonly Z3Runner $z3,
        private readonly CounterexampleFormatter $formatter,
        private readonly int $timeoutMs,
    ) {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getOriginalNode();

        try {
            $contract = $this->contracts->read($method);
            if ($contract->isEmpty() || $method->stmts === null) {
                return [];
            }

            $encoding = $this->encoder->encode($node, $scope, $contract);
            $results = $this->z3->run($this->renderer->render($encoding), $this->renderer->checkCount($encoding));
        } catch (VerificationRejected $rejected) {
            return [$this->error($rejected->getMessage(), $rejected->identifier, $rejected->errorLine)];
        } catch (SolverFailure $failure) {
            return [$this->error($failure->getMessage(), 'contracts.solver', $method->getStartLine())];
        }

        return $this->interpret($encoding, $results, $method->getStartLine());
    }

    /**
     * @param list<SolverResult> $results
     * @return list<IdentifierRuleError>
     */
    private function interpret(Encoding $encoding, array $results, int $methodLine): array
    {
        $cursor = 0;

        if ($results[$cursor++]->verdict === Verdict::Unsat) {
            return [$this->error(
                'The preconditions are contradictory: no input satisfies the parameter types and #[Requires], so every proof would be vacuous.',
                'contracts.contradictoryRequires',
                $methodLine,
            )];
        }

        $errors = [];

        foreach ($encoding->obligations as $obligation) {
            $result = $results[$cursor++];

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        '%s Counterexample: %s.',
                        $this->describeObligation($obligation),
                        $this->formatter->format($encoding, $result->values, false),
                    ),
                    'contracts.' . $obligation->kind->value,
                    $obligation->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided($this->claimOf($obligation), $obligation->line);
            }
        }

        foreach ($encoding->ensures as $ensures) {
            $result = $results[$cursor++];

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        'Postcondition #[Ensures(\'%s\')] can be violated. Counterexample: %s.',
                        $ensures->source,
                        $this->formatter->format($encoding, $result->values, true),
                    ),
                    'contracts.ensuresViolated',
                    $ensures->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided(sprintf('#[Ensures(\'%s\')]', $ensures->source), $ensures->line);
            }
        }

        $returnTypeCheck = $encoding->returnTypeCheck;
        if ($returnTypeCheck !== null) {
            $result = $results[$cursor++];

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        '%s Counterexample: %s.',
                        $this->describeObligation($returnTypeCheck),
                        $this->formatter->format($encoding, $result->values, true),
                    ),
                    'contracts.' . $returnTypeCheck->kind->value,
                    $returnTypeCheck->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided($this->claimOf($returnTypeCheck), $returnTypeCheck->line);
            }
        }

        return $errors;
    }

    private function describeObligation(Obligation $obligation): string
    {
        $description = match ($obligation->kind) {
            ObligationKind::Overflow => sprintf('Possible integer overflow: `%s` can leave the PHP int range.', $obligation->expression),
            ObligationKind::DivisionByZero => sprintf('Possible division by zero in `%s`.', $obligation->expression),
            ObligationKind::UnhandledMatch => 'This match expression can throw UnhandledMatchError.',
            ObligationKind::MissingReturn => 'Not every code path returns a value.',
            ObligationKind::InvalidEnumValue => sprintf('`%s` can throw ValueError: the value may not be a backing value of the enum. Use tryFrom() for untrusted input.', $obligation->expression),
            ObligationKind::InvalidCast => sprintf('`%s` is undefined for NaN, infinities and values outside the int range.', $obligation->expression),
            ObligationKind::ReturnType => sprintf('The returned value can violate the declared return type `%s`.', $obligation->expression),
            ObligationKind::RequiresViolated => sprintf('The call to %s can violate its precondition #[Requires(\'%s\')].', $obligation->context ?? 'the method', $obligation->expression),
        };

        if ($obligation->context === null || $obligation->kind === ObligationKind::RequiresViolated) {
            return $description;
        }

        return sprintf('%s, in the call to %s.', substr($description, 0, -1), $obligation->context);
    }

    private function claimOf(Obligation $obligation): string
    {
        $claim = $this->plainClaimOf($obligation);

        return $obligation->context === null || $obligation->kind === ObligationKind::RequiresViolated
            ? $claim
            : sprintf('%s, in the call to %s', $claim, $obligation->context);
    }

    private function plainClaimOf(Obligation $obligation): string
    {
        return match ($obligation->kind) {
            ObligationKind::RequiresViolated => sprintf('the precondition #[Requires(\'%s\')] of %s', $obligation->expression, $obligation->context ?? 'the method'),
            ObligationKind::Overflow => sprintf('no integer overflow in `%s`', $obligation->expression),
            ObligationKind::DivisionByZero => sprintf('no division by zero in `%s`', $obligation->expression),
            ObligationKind::UnhandledMatch => 'match exhaustiveness',
            ObligationKind::MissingReturn => 'a return on every path',
            ObligationKind::InvalidEnumValue => sprintf('a valid backing value in `%s`', $obligation->expression),
            ObligationKind::InvalidCast => sprintf('a representable value in `%s`', $obligation->expression),
            ObligationKind::ReturnType => sprintf('the return type `%s`', $obligation->expression),
        };
    }

    private function undecided(string $claim, int $line): IdentifierRuleError
    {
        return $this->error(
            sprintf('Not proven: Z3 answered "unknown" after %d ms for %s.', $this->timeoutMs, $claim),
            'contracts.unknown',
            $line,
        );
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier($identifier)->line($line)->build();
    }
}
