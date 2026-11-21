<?php

namespace App\Conversion;

use RuntimeException;
use Throwable;

class ConversionFailed extends RuntimeException
{
    public function __construct(
        public readonly ConversionFailure $failure,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail !== '' ? $detail : $failure->value, 0, $previous);
    }

    public static function because(ConversionFailure $failure, string $detail = ''): self
    {
        return new self($failure, $detail);
    }
}
