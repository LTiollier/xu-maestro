<?php

namespace App\Exceptions;

class RunCancelledException extends \RuntimeException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct("Run '{$runId}' was cancelled");
    }
}
