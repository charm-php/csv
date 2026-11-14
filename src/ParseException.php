<?php

declare(strict_types=1);

namespace Charm\Csv;

/**
 * Malformed CSV input, thrown by the Decoder in Mode::Strict. It is an \UnexpectedValueException, so
 * `catch (\UnexpectedValueException)` catches a data error from any charm package.
 *
 * Positions are 1-based. `lineNumber` counts physical lines over the whole input (also across chunks),
 * `column` counts UTF-8 characters within that line, `record` counts records.
 */
final class ParseException extends \UnexpectedValueException
{
    public function __construct(
        string $reason,
        public readonly int $lineNumber,
        public readonly int $column,
        public readonly int $record,
    ) {
        parent::__construct("$reason at line $lineNumber, column $column (record $record)");
    }
}
