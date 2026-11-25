<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

class StatelessParentWithState
{
    private int $hidden = 0;

    public function hidden(): int
    {
        return $this->hidden;
    }
}
