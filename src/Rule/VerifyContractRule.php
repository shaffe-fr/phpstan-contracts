<?php

declare(strict_types=1);

namespace PhpstanContracts\Rule;

use PhpstanContracts\Contract\ContractReader;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Report\CounterexampleFormatter;
use PhpstanContracts\Report\UnknownExplainer;
use PhpstanContracts\Solver\MethodSolver;
use PhpstanContracts\Solver\RunBudgetExhausted;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\UnknownReason;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\LoopBounds;
use PhpstanContracts\Translate\MethodEncoder;
use PhpstanContracts\Translate\Obligation;
use PhpstanContracts\Translate\ObligationKind;
use PhpstanContracts\Translate\ProbePlanner;
use LogicException;
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

    private readonly UnknownExplainer $explainer;

    public function __construct(
        private readonly ContractReader $contracts,
        private readonly MethodEncoder $encoder,
        private readonly ScriptRenderer $renderer,
        private readonly Z3Runner $z3,
        private readonly MethodSolver $solver,
        private readonly CounterexampleFormatter $formatter,
        private readonly int $timeoutMs,
        private readonly int $methodBudgetMs,
        int $loopUnroll = 10,
        int $maxLoopUnroll = 100,
    ) {
        // The translation uses these two numbers; they are checked here, where the other settings of the rule are.
        LoopBounds::validateLimits($loopUnroll, $maxLoopUnroll);
        $this->explainer = new UnknownExplainer();
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
            $answer = $this->solver->solve($encoding);
        } catch (VerificationRejected $rejected) {
            return [$this->error($rejected->getMessage(), $rejected->identifier, $rejected->errorLine)];
        } catch (RunBudgetExhausted $exhausted) {
            return [$this->error($this->runBudgetMessage($exhausted), 'contracts.unknown', $method->getStartLine())];
        } catch (SolverFailure $failure) {
            return [$this->error($failure->getMessage(), 'contracts.solver', $method->getStartLine())];
        }

        return $this->interpret($encoding, $answer->results, $method->getStartLine(), $answer->undecidedLengths);
    }

    /**
     * @param list<SolverResult> $results
     * @param array<int, list<int|string>> $undecidedLengths by check number, the cases of the split by length that were not decided
     * @return list<IdentifierRuleError>
     */
    private function interpret(Encoding $encoding, array $results, int $methodLine, array $undecidedLengths = []): array
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
                $this->unknownMessage($results[0], 'the satisfiability of the parameter types and #[Requires], so every proof of this method may be vacuous'),
                'contracts.unknown',
                $methodLine,
            );
        }

        $reported = $this->reportedCopies($encoding, $results, $cursor);
        foreach ($encoding->obligations as $position => $obligation) {
            $check = $cursor++;
            $result = $results[$check];
            if ($reported[$position] !== true) {
                continue;
            }

            if ($result->verdict === Verdict::Sat) {
                $errors[] = $this->error(
                    sprintf(
                        '%s Counterexample: %s.',
                        $this->inIteration($this->describeObligation($obligation), $obligation),
                        $this->counterexample($encoding, $check, $result, false, $this->isBeyondShortLists($obligation)),
                    ),
                    'contracts.' . $obligation->kind->value,
                    $obligation->line,
                );
            } elseif ($result->verdict === Verdict::Unknown) {
                $errors[] = $this->undecided($encoding, $this->claimOf($obligation), $obligation->line, $result, $undecidedLengths[$check] ?? []);
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
                $errors[] = $this->undecided($encoding, sprintf('#[Ensures(\'%s\')]', $ensures->source), $ensures->line, $result, $undecidedLengths[$check] ?? []);
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
                $errors[] = $this->undecided($encoding, $declaration, $throws->line, $result, $undecidedLengths[$check] ?? []);
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
                $errors[] = $this->undecided($encoding, $this->claimOf($returnTypeCheck), $returnTypeCheck->line, $result, $undecidedLengths[$check] ?? []);
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
    private function counterexample(Encoding $encoding, int $check, SolverResult $result, bool $withResult, bool $keepLongForm = false): string
    {
        $text = $this->formatter->format($encoding, $result->values, $withResult);
        if ($keepLongForm || !$this->formatter->isTruncated($text)) {
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
            ObligationKind::LoopBound => $this->describeLoopBound($obligation),
            ObligationKind::UnexpectedThrow => $obligation->allowedWhere === null
                ? sprintf('`%s` can be reached where no #[Throws] allows it.', $obligation->expression)
                : sprintf('`%s` can be reached where the #[Throws] declarations do not allow it: they allow it only where %s.', $obligation->expression, $obligation->allowedWhere),
        };

        return $through === null ? $description : sprintf('%s, in the call to %s.', substr($description, 0, -1), $through);
    }

    /**
     * Which obligations are reported. Each iteration of a loop has its own copy of every
     * obligation of the body, and the copies of one location are reported once: the first one
     * Z3 refutes, which is the earliest iteration that can fail (each copy is checked assuming
     * the earlier ones), or else one that Z3 could not decide. Reporting one copy cannot hide a
     * failure: the method already fails.
     *
     * @param list<SolverResult> $results
     * @param int $first the check number of the first obligation
     * @return list<bool> by position in the obligations of the encoding
     */
    private function reportedCopies(Encoding $encoding, array $results, int $first): array
    {
        /** @var array<string, array{int|null, int|null}> $chosen the refuted and the undecided copy of each group */
        $chosen = [];
        foreach ($encoding->obligations as $position => $obligation) {
            if ($obligation->iterations === []) {
                continue;
            }
            $key = $obligation->groupKey();
            $chosen[$key] ??= [null, null];
            $verdict = $results[$first + $position]->verdict;
            if ($verdict === Verdict::Sat) {
                $chosen[$key][0] ??= $position;
            } elseif ($verdict === Verdict::Unknown) {
                $chosen[$key][1] ??= $position;
            }
        }

        $reported = [];
        foreach ($encoding->obligations as $position => $obligation) {
            $reported[] = $obligation->iterations === []
                || $position === ($chosen[$obligation->groupKey()][0] ?? $chosen[$obligation->groupKey()][1]);
        }

        return $reported;
    }

    /**
     * Where in a loop the reported copy of an obligation failed: `in iteration 3 (key 2) of the
     * foreach at line 12`, the innermost loop first.
     */
    private function inIteration(string $description, Obligation $obligation): string
    {
        // A loop bound is about the list, not about an iteration.
        if ($obligation->iterations === [] || $obligation->kind === ObligationKind::LoopBound) {
            return $description;
        }

        $where = array_map(
            static fn (array $iteration): string => sprintf('in iteration %d (key %d) of the foreach at line %d', $iteration[1] + 1, $iteration[1], $iteration[0]),
            array_reverse($obligation->iterations),
        );

        return sprintf('%s, %s.', substr($description, 0, -1), implode(', ', $where));
    }

    private function describeLoopBound(Obligation $obligation): string
    {
        $loop = $obligation->loop ?? throw new LogicException('A loop bound obligation describes its loop.');

        $unrolled = $loop->origin === null
            ? sprintf('The proof unrolls this foreach up to %d iterations, and %s can have more.', $loop->iterations, $loop->subject)
            : sprintf('The proof unrolls this foreach up to %d iterations, the bound of #[Requires(\'%s\')], and %s can have more.', $loop->iterations, $loop->origin, $loop->subject);

        if ($loop->nameable) {
            $example = sprintf('#[Requires(\'count(%s) <= %d\')]', $loop->subject, $loop->origin === null ? 50 : $loop->iterations);
            $way = $loop->origin === null
                ? sprintf('If the real maximum is known, add %s; otherwise raise contracts.loopUnroll.', $example)
                : sprintf('If the real maximum is known, add %s, or raise the bound of the #[Requires] above.', $example);
        } else {
            $way = $loop->origin === null
                ? 'If the real maximum is known, bound the list it is built from with #[Requires]; otherwise raise contracts.loopUnroll.'
                : 'If the real maximum is known, bound the list it is built from with #[Requires], or raise the bound of the #[Requires] above.';
        }

        return sprintf('%s This is a limit of the proof, not a bug in the code. %s', $unrolled, $way);
    }

    /**
     * A list that must exceed the loop bound cannot be shortened to ten elements when asking for
     * a counterexample that can be printed in full.
     */
    private function isBeyondShortLists(Obligation $obligation): bool
    {
        return $obligation->kind === ObligationKind::LoopBound
            && $obligation->loop !== null
            && $obligation->loop->iterations >= ProbePlanner::MAX_ELEMENTS;
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
            ObligationKind::LoopBound => sprintf('the loop bound `%s`', $obligation->expression),
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

    /**
     * @param list<int|string> $undecidedLengths the cases of the split by length that were not decided: lengths, and the description of the case outside the bound
     */
    private function undecided(Encoding $encoding, string $claim, int $line, SolverResult $result, array $undecidedLengths = []): IdentifierRuleError
    {
        $message = $this->unknownMessage($result, $claim);
        $cost = $this->explainer->explain($encoding, $undecidedLengths);

        return $this->error($cost === '' ? $message : $message . ' ' . $cost, 'contracts.unknown', $line);
    }

    /**
     * Why the check is not proven. Z3 giving up at its limit, the budget of the method that ran out
     * before the check was tried in full, and an earlier run that left it undecided are three different
     * facts, each with its own way out.
     */
    private function unknownMessage(SolverResult $result, string $claim): string
    {
        return match ($result->reason) {
            UnknownReason::Budget => sprintf(
                'Not proven: the time budget of this method (contracts.methodBudgetMs, %d ms) ran out before Z3 decided %s. Raise contracts.methodBudgetMs, or set it to 0 for no limit.',
                $this->methodBudgetMs,
                $claim,
            ),
            UnknownReason::Remembered => sprintf(
                'Not proven: an earlier run, with the same limits (contracts.timeoutMs %d ms, contracts.methodBudgetMs %d ms), left %s undecided, so Z3 was not asked again. To try again, clear the cache directory (contracts.cacheDirectory) or change one of these limits.',
                $this->timeoutMs,
                $this->methodBudgetMs,
                $claim,
            ),
            null => sprintf('Not proven: Z3 answered "unknown" after %d ms for %s.', $this->timeoutMs, $claim),
        };
    }

    private function runBudgetMessage(RunBudgetExhausted $exhausted): string
    {
        return sprintf(
            'Not verified: the solver budget of this run is exhausted (%d ms spent, contracts.runBudgetMs is %d ms), so Z3 was not asked about this method. Raise contracts.runBudgetMs, or set it to 0 for no limit. The next run starts again with a fresh budget.',
            $exhausted->spentMs,
            $exhausted->limitMs,
        );
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier($identifier)->line($line)->build();
    }
}
