<?php

declare(strict_types=1);

/*
 * Functional facade with the names and parameters of the proposed ext/csv API (PHP RFC "CSV Extension"),
 * so code written for it can switch by changing the namespace import. For anything beyond
 * delimiter/enclosure/terminator (quoting modes, headers, streams, column output) use Dialect, Reader and Writer.
 *
 * Differences worth knowing: terminators given here are used for writing; reading accepts
 * CRLF, LF and CR whenever the terminator is one of them. The strict functions throw ParseException
 * (a RuntimeException) rather than ValueError. Field values may also be Stringable objects.
 */

namespace Charm\Csv;

/** One row to one CSV record string, including the line terminator. */
function array_to_row(array $fields, string $delimiter = ',', string $enclosure = '"', string $eolSequence = "\r\n"): string
{
    return Writer::rowToString($fields, new Dialect($delimiter, $enclosure, $eolSequence));
}

/** Parse exactly one record. ValueError if the string holds none or several. */
function row_to_array(string $row, string $delimiter = ',', string $enclosure = '"', string $eolSequence = "\r\n"): array
{
    $rows = Reader::fromString($row, new Dialect($delimiter, $enclosure, $eolSequence, strict: true))->toArray();
    if (count($rows) !== 1) {
        throw new \ValueError('row_to_array() expects exactly one record, found ' . count($rows));
    }
    return $rows[0];
}

/** @param iterable<array> $collection */
function collection_to_buffer(iterable $collection, string $delimiter = ',', string $enclosure = '"', string $eolSequence = "\r\n"): string
{
    return Writer::rowsToString($collection, new Dialect($delimiter, $enclosure, $eolSequence));
}

/** Strict: RFC 4180 grammar and the same field count in every row, or ParseException. */
function buffer_to_collection(string $buffer, string $delimiter = ',', string $enclosure = '"', string $eolSequence = "\r\n"): array
{
    return Reader::fromString($buffer, new Dialect($delimiter, $enclosure, $eolSequence, strict: true))
        ->requireConsistentFields()
        ->toArray();
}

/** Lax: recovers from malformed quoting (see README) and allows rows of varying width. */
function buffer_to_collection_lax(string $buffer, string $delimiter = ',', string $enclosure = '"', string $eolSequence = "\r\n"): array
{
    return Reader::fromString($buffer, new Dialect($delimiter, $enclosure, $eolSequence))->toArray();
}

/** @param iterable<array> $collection */
function collection_to_file(string $file, iterable $collection, string $delimiter = ',', string $enclosure = '"', string $eolSequence = "\r\n"): void
{
    $w = Writer::toFile($file, new Dialect($delimiter, $enclosure, $eolSequence));
    $w->writeRows($collection);
    $w->close();
}
