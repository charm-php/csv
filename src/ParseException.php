<?php

declare(strict_types=1);

namespace FrodeBorli\Csv;

/** Malformed CSV input. Positions are 1-based; `column` counts UTF-8 characters. */
final class ParseException extends \RuntimeException
{
    public function __construct(
        string $reason,
        public readonly int $csvLine,
        public readonly int $column,
        public readonly int $record,
    ) {
        parent::__construct("$reason at line $csvLine, column $column (record $record)");
    }
}
