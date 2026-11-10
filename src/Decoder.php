<?php

declare(strict_types=1);

namespace Charm\Csv;

/**
 * Incremental CSV decoder. Feed it input in chunks of any size and any alignment (a chunk may end
 * in the middle of a field, a quoted newline or a multibyte character); it returns the records
 * completed so far. Where the chunks are cut never changes the result.
 *
 *     $decoder = new Decoder(header: true);
 *     $h = fopen('data.csv', 'rb');
 *     while (!feof($h)) {
 *         foreach ($decoder->decode(fread($h, 65536)) as $row) { ... }
 *     }
 *     foreach ($decoder->finish() as $row) { ... }
 *
 * Semantics:
 *  - Records end with "\r\n", "\n" or "\r" when the Format's eolSequence is one of those (any other
 *    sequence is matched exactly). Blank lines are skipped, so a record holding one empty field must
 *    be written as `""` (the Encoder does).
 *  - A UTF-8 BOM at the very start is stripped.
 *  - Fields are strings; with Format::$null set, unquoted fields equal to it become null (a quoted one stays a string).
 *  - Header `false`: records are lists. `true`: the first record names the columns and later records
 *    are associative arrays. A list of names: every record is associative with those names. PHP turns
 *    numeric-string names such as "1" into integer keys.
 *  - Mode::Strict (default) throws ParseException on malformed input, on a record whose field count
 *    differs from the first record's (or from the header's), and on empty or duplicate header names.
 *    Mode::Lax recovers like fgetcsv(); with a header a short record is padded with null and a long
 *    one keeps its extra fields under integer keys (their position). Empty or duplicate header names
 *    are renamed `column_N` / `name_2`.
 *  - A record is returned as soon as the decoder knows it is complete. A record ending in a lone "\r"
 *    (possibly half of "\r\n") or in a multibyte token waits for the next chunk or for finish().
 *  - After a ParseException the decoder is in an undefined state: discard it. If a chunk holds several
 *    errors, the first in the input is reported, except that a later error in the same chunk may be reported instead of an invalid
 *    header.
 *  - Limit: a record that is still incomplete is parsed again when more input arrives, so feeding a
 *    single record of size N in very small chunks costs O(N²/chunk). Use chunks of a few KiB or more.
 */
final class Decoder
{
    private const BOM = "\xEF\xBB\xBF";

    private Scanner $scanner;
    private string $buf = '';
    private bool $head = true;
    /** @var list<int|string>|null column names once known (header modes) */
    private ?array $names = null;
    private bool $post;

    /**
     * @param bool|list<int|string> $header false: lists; true: first record is the header; list: these column names
     */
    public function __construct(private Format $format = new Format(), private bool|array $header = false, private Mode $mode = Mode::Strict)
    {
        if (is_array($header)) {
            $header = array_values($header);
            if (!$header || count(array_unique($header)) !== count($header) || in_array('', $header, true)) {
                throw new \ValueError('Header names must be non-empty and unique');
            }
            $this->header = $header;
        }
        $this->post = $header !== false;
        $this->reset();
    }

    /**
     * Add input and return the records it completed (possibly none). Records are lists of strings,
     * or associative arrays when a header is in use.
     *
     * @return list<array<int|string, ?string>>
     */
    public function decode(string $chunk): array
    {
        if ($this->head) { // a BOM may arrive in pieces
            $this->buf .= $chunk;
            if (strlen($this->buf) < 3) {
                return [];
            }
            $this->head = false;
            if (str_starts_with($this->buf, self::BOM)) {
                $this->buf = substr($this->buf, 3);
            }
            $chunk = '';
        }
        $buf = $this->buf === '' ? $chunk : $this->buf . $chunk;
        $rows = $this->scanner->scan($buf, false);
        $this->buf = $this->scanner->consumed >= strlen($buf) ? '' : substr($buf, $this->scanner->consumed);
        return $this->post ? $this->shape($rows) : $rows;
    }

    /**
     * Signal the end of input: returns the last record (input that does not end with an EOL) and, in
     * Mode::Strict, throws on an unterminated enclosure. The decoder is reset afterwards and can be
     * used for another input.
     *
     * @return list<array<int|string, ?string>>
     */
    public function finish(): array
    {
        $rows = $this->scanner->scan($this->buf, true);
        $rows = $this->post ? $this->shape($rows) : $rows;
        $this->reset();
        return $rows;
    }

    private function reset(): void
    {
        $this->scanner = new Scanner($this->format, $this->mode === Mode::Strict);
        $this->buf = '';
        $this->head = true;
        $this->names = is_array($this->header) ? $this->header : null;
        $this->scanner->expect = $this->names === null ? null : count($this->names);
    }

    /** Header handling and null substitution for the records of one scan. */
    private function shape(array $rows): array
    {
        if ($this->header === true && $this->names === null && $rows) {
            $this->names = $this->namesFrom(array_shift($rows));
        }
        $names = $this->names;
        if ($names === null) {
            return $rows;
        }
        $k = count($names);
        foreach ($rows as $i => $row) {
            $c = count($row);
            if ($c === $k) {
                $rows[$i] = array_combine($names, $row);
            } elseif ($c < $k) { // only reachable in Lax mode
                $rows[$i] = array_combine($names, array_pad($row, $k, null));
            } else {
                $rows[$i] = array_combine($names, array_slice($row, 0, $k)) + array_slice($row, $k, null, true);
            }
        }
        return $rows;
    }

    /** Strict rejects empty and duplicate names, Lax repairs them. @return list<string> */
    private function namesFrom(array $raw): array
    {
        $seen = [];
        foreach ($raw as $i => $name) {
            $name ??= ''; // an unquoted null text
            if ($name === '' || isset($seen[$name])) {
                $why = $name === '' ? 'empty column name' : "duplicate column name '$name'";
                if ($this->mode === Mode::Strict) {
                    throw new ParseException($why, 1, 1, 1);
                }
                $base = $name === '' ? 'column_' . ($i + 1) : $name;
                $name = $base;
                for ($n = 2; isset($seen[$name]); $n++) {
                    $name = "{$base}_$n";
                }
            }
            $seen[$name] = true;
            $raw[$i] = $name;
        }
        return $raw;
    }
}
