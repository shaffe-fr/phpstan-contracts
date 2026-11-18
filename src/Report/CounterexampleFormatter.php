<?php

declare(strict_types=1);

namespace PhpstanContracts\Report;

use PhpstanContracts\Translate\Encoding;

final class CounterexampleFormatter
{
    public function __construct(private readonly ValueFormatter $values)
    {
    }

    /**
     * @param array<string, string|list<mixed>> $values
     */
    public function format(Encoding $encoding, array $values, bool $withResult): string
    {
        $inputs = array_map(
            fn ($input): string => sprintf('$%s = %s', $input->phpName, $this->values->format($input->sort, $values[$input->smtName] ?? null)),
            $encoding->inputs,
        );

        $text = $inputs === [] ? 'no parameters' : implode(', ', $inputs);

        if (!$withResult || $encoding->result === null) {
            return $text;
        }

        $text .= sprintf(' => $result = %s', $this->values->format($encoding->result->sort, $values[$encoding->result->smtName] ?? null));

        foreach ($encoding->returnMarkers as $marker) {
            if (($values[$marker->smtName] ?? null) === 'true') {
                $text .= sprintf(' (return at line %d)', $marker->line);
            }
        }

        return $text;
    }
}
