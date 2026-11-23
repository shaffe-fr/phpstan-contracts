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
use PhpstanContracts\Translate\ProbePlanner;
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
    private const BODILESS = 'Contracts on abstract and interface methods are not verified, and the methods that implement them do not inherit them: put the contract on each concrete method.';

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
            if ($contract->isEmpty()) {
                return [];
            }
            if ($method->stmts === null) {
                return [$this->error(self::BODILESS, 'contracts.unsupported', $method->getStartLine())];
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
        $errors = [];

        $assumptions = $results[$cursor++]->verdict;
        if ($assumptions === Verdict::Unsat) {
            return [$this->error(
                'The preconditions are contradictory: no input satisfies the parameter types and #[Requires], so every proof would be vacuous.',
                'contracts.contradictoryRequires',
                $methodLine,
            )];
        }
        if ($assumptions === Verdict::Unknown) {
            $errors[] = $this->error(
                sprintf(
                    'Not proven: Z3 answered "unknown" after %d ms for the satisfiability of the parameter types and #[Requires], so every proof of this method may be vacuous.',
                    $this->timeoutMs,
                ),
                'contracts.unknown',
                $methodLine,
            );
        }

        foreach ($encoding->obligations as $obligation) {
            $check = $cursor++;
            $result = $results[$check];

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        '%s Counterexample: %s.',
                        $this->describeObligation($obligation),
                        $this->counterexample($encoding, $check, $result, false),
                    ),
                    'contracts.' . $obligation->kind->value,
                    $obligation->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided($this->claimOf($obligation), $obligation->line);
            }
        }

        foreach ($encoding->ensures as $ensures) {
            $check = $cursor++;
            $result = $results[$check];

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        'Postcondition #[Ensures(\'%s\')] can be violated. Counterexample: %s.',
                        $ensures->source,
                        $this->counterexample($encoding, $check, $result, true),
                    ),
                    'contracts.ensuresViolated',
                    $ensures->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided(sprintf('#[Ensures(\'%s\')]', $ensures->source), $ensures->line);
            }
        }

        foreach ($encoding->throwsChecks as $throws) {
            $check = $cursor++;
            $result = $results[$check];
            $declaration = sprintf('#[Throws(%s::class, when: \'%s\')]', $throws->class, $throws->when);

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        '%s is violated: the method can return although the condition holds. Counterexample: %s.',
                        $declaration,
                        $this->counterexample($encoding, $check, $result, true),
                    ),
                    'contracts.throwsViolated',
                    $throws->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided($declaration, $throws->line);
            }
        }

        $returnTypeCheck = $encoding->returnTypeCheck;
        if ($returnTypeCheck !== null) {
            $check = $cursor++;
            $result = $results[$check];

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        '%s Counterexample: %s.',
                        $this->describeObligation($returnTypeCheck),
                        $this->counterexample($encoding, $check, $result, true),
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

    /**
     * Z3 picks the length of a list freely, and a counterexample that needs one element can come
     * back with thousands. When the model holds a list too long to print, the check is asked
     * again with every probed list kept to {@see ProbePlanner::MAX_ELEMENTS} elements: that model
     * satisfies everything the first one did, so it is as genuine, and it can be printed in full.
     * If the second question is not answered with a model, the long form is kept.
     */
    private function counterexample(Encoding $encoding, int $check, SolverResult $result, bool $withResult): string
    {
        $text = $this->formatter->format($encoding, $result->values, $withResult);
        if (!$this->formatter->isTruncated($text)) {
            return $text;
        }

        try {
            $bounded = $this->z3->run($this->renderer->renderBoundedCheck($encoding, $check, ProbePlanner::MAX_ELEMENTS), 1)[0];
        } catch (SolverFailure) {
            return $text;
        }

        return $bounded->verdict === Verdict::Sat ? $this->formatter->format($encoding, $bounded->values, $withResult) : $text;
    }

    private function describeObligation(Obligation $obligation): string
    {
        [$callee, $through] = $this->callsOf($obligation);
        $description = match ($obligation->kind) {
            ObligationKind::Overflow => sprintf('Possible integer overflow: `%s` can leave the PHP int range.', $obligation->expression),
            ObligationKind::DivisionByZero => sprintf('Possible division by zero in `%s`.', $obligation->expression),
            ObligationKind::UnhandledMatch => 'This match expression can throw UnhandledMatchError.',
            ObligationKind::MissingReturn => 'Not every code path returns a value.',
            ObligationKind::InvalidEnumValue => sprintf('`%s` can throw ValueError: the value may not be a backing value of the enum. Use tryFrom() for untrusted input.', $obligation->expression),
            ObligationKind::InvalidCast => sprintf('`%s` loses the value for NaN, infinities and values outside the int range: PHP 8.3 and 8.4 wrap it or return 0, and PHP 8.5 also raises a warning.', $obligation->expression),
            ObligationKind::ReturnType => sprintf('The returned value can violate the declared return type `%s`.', $obligation->expression),
            ObligationKind::RequiresViolated => sprintf('The call to %s can violate its precondition #[Requires(\'%s\')].', $callee ?? 'the method', $obligation->expression),
            ObligationKind::UndefinedKey => sprintf(
                '`%s` can read a key that does not exist: PHP raises the warning "Undefined array key" and yields null. Guard the read with `%s` or `isset(%s)`, or state the range with #[Requires].',
                $obligation->expression,
                $obligation->guard ?? 'count($list) > $index',
                $obligation->expression,
            ),
            ObligationKind::UnexpectedThrow => $obligation->allowedWhere === null
                ? sprintf('`%s` can be reached where no #[Throws] allows it.', $obligation->expression)
                : sprintf('`%s` can be reached where the #[Throws] declarations do not allow it: they allow it only where %s.', $obligation->expression, $obligation->allowedWhere),
        };

        return $through === null ? $description : sprintf('%s, in the call to %s.', substr($description, 0, -1), $through);
    }

    private function claimOf(Obligation $obligation): string
    {
        [$callee, $through] = $this->callsOf($obligation);
        $claim = match ($obligation->kind) {
            ObligationKind::RequiresViolated => sprintf('the precondition #[Requires(\'%s\')] of %s', $obligation->expression, $callee ?? 'the method'),
            ObligationKind::UnexpectedThrow => sprintf('no unexpected `%s`', $obligation->expression),
            ObligationKind::Overflow => sprintf('no integer overflow in `%s`', $obligation->expression),
            ObligationKind::DivisionByZero => sprintf('no division by zero in `%s`', $obligation->expression),
            ObligationKind::UnhandledMatch => 'match exhaustiveness',
            ObligationKind::MissingReturn => 'a return on every path',
            ObligationKind::InvalidEnumValue => sprintf('a valid backing value in `%s`', $obligation->expression),
            ObligationKind::InvalidCast => sprintf('a representable value in `%s`', $obligation->expression),
            ObligationKind::ReturnType => sprintf('the return type `%s`', $obligation->expression),
            ObligationKind::UndefinedKey => sprintf('an existing key in `%s`', $obligation->expression),
        };

        return $through === null ? $claim : sprintf('%s, in the call to %s', $claim, $through);
    }

    /**
     * The method whose precondition is checked, and the chain of calls that leads to
     * the obligation from the line where it is reported: `B(), through C()` when the
     * verified method calls B(), which calls C().
     *
     * @return array{string|null, string|null}
     */
    private function callsOf(Obligation $obligation): array
    {
        $calls = $obligation->calls;
        $callee = $obligation->kind === ObligationKind::RequiresViolated ? array_pop($calls) : null;

        return [$callee, $calls === [] ? null : implode(', through ', $calls)];
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
