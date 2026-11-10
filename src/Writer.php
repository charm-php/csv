<?php

declare(strict_types=1);

namespace FrodeBorli\Csv;

/**
 * CSV writer. Rows are encoded into an internal buffer that is flushed to the stream in
 * large writes (default 1 MiB), never per field.
 *
 *     $w = Writer::toFile('out.csv');                  // or Writer::toStream(fopen('php://output', 'w'))
 *     $w->writeRows($generatorOfRows);                 // lists, or associative rows (header derived from the first)
 *     $w->close();
 *
 * Field values: string, int, float, bool (true = "1", false = ""), null (empty), Stringable.
 * Anything else throws TypeError. Floats are written round-trip exact and locale-independent.
 *
 * A field is enclosed if it contains the delimiter, the enclosure or a line break, if it
 * ends with a fragment of a multibyte token that could form a token with its
 * neighbour, or if it is the only (empty) field of a row (otherwise the row would read back as a blank line).
 * With skipInitialSpace, fields starting with a space are enclosed too. So is a very first field starting with a UTF-8 BOM,
 * which a reader would otherwise strip.
 * An empty row (no fields) is written as a blank line, which readers skip.
 */
final class Writer
{
    private string $buf = '';
    private bool $closed = false;
    private bool $started = false;
    /** @var list<int|string>|null */
    private ?array $header = null;

    // Precomputed from the dialect.
    private string $special;
    /** @var list<string> multibyte tokens that must not occur in an unquoted field */
    private array $multi = [];
    /** @var list<string> */
    private array $tailBad = [];
    private bool $extra;

    /**
     * @param resource|null $stream Where to write; null collects into memory (see contents()).
     */
    public function __construct(private $stream = null, private ?Dialect $dialect = null, private int $bufferSize = 1 << 20, private bool $owns = false)
    {
        $d = $this->dialect ??= new Dialect();
        $quoting = $d->quoting;
        $tokens = [$d->delimiter, ...$d->eols()];
        if ($quoting !== Quoting::NONE) { // with NONE the enclosure is not special, so it never forces quoting
            $tokens[] = $d->enclosure;
        }
        $this->special = '';
        foreach ($tokens as $t) {
            if (strlen($t) === 1) { $this->special .= $t; }
            elseif ($t !== "\r\n") { $this->multi[] = $t; }
        }
        // A field ending in a proper prefix of a multibyte token could form that token together with the
        // delimiter or terminator that follows ("a|" + "||" reads as "a" + "||" + "|"). A fragment at the
        // start of a field is harmless: readers match tokens left to right, from a known position.
        foreach ($this->multi as $t) {
            for ($k = 1; $k < strlen($t); $k++) {
                $this->tailBad[] = substr($t, 0, $k);
            }
        }
        $this->extra = $this->multi || $d->skipInitialSpace;
        if ($d->bom) { $this->buf = "\xEF\xBB\xBF"; }
    }

    public static function toFile(string $path, ?Dialect $dialect = null, int $bufferSize = 1 << 20): self
    {
        $h = @fopen($path, 'wb');
        if ($h === false) {
            throw new \RuntimeException("Cannot open '$path' for writing");
        }
        return new self($h, $dialect, $bufferSize, true);
    }

    /** @param resource $stream Not closed by close() (only flushed); e.g. php://output. */
    public static function toStream($stream, ?Dialect $dialect = null, int $bufferSize = 1 << 20): self
    {
        if (!is_resource($stream)) {
            throw new \ValueError('Writer::toStream() expects a stream resource');
        }
        return new self($stream, $dialect, $bufferSize);
    }

    public static function rowToString(iterable $row, ?Dialect $dialect = null): string
    {
        $w = new self(null, $dialect);
        $w->append(is_array($row) ? $row : iterator_to_array($row, false)); // values only, no header detection
        return $w->contents();
    }

    /** @param iterable<iterable> $rows */
    public static function rowsToString(iterable $rows, ?Dialect $dialect = null, ?array $header = null): string
    {
        $w = new self(null, $dialect);
        if ($header !== null) { $w->writeHeader($header); }
        $w->writeRows($rows);
        return $w->contents();
    }

    /** Write a header row and fix the column order: associative rows are then written in this order. */
    public function writeHeader(array $names): void
    {
        $this->header = array_values($names);
        $this->append($this->header);
    }

    /**
     * Associative rows follow the header (derived from the first row's keys if none was set);
     * missing keys are written empty, unknown keys throw ValueError. List rows are written as they are.
     */
    public function writeRow(iterable $row): void
    {
        if (!is_array($row)) {
            $row = iterator_to_array($row);
        }
        if (!array_is_list($row)) {
            if ($this->header === null) {
                $this->writeHeader(array_keys($row));
            }
            $keys = array_keys($row);
            if ($keys !== $this->header) {
                if (array_diff_key($row, array_flip($this->header))) {
                    throw new \ValueError('Row has keys that are not in the header: ' . implode(', ', array_diff($keys, $this->header)));
                }
                $ordered = [];
                foreach ($this->header as $name) { $ordered[] = $row[$name] ?? ''; }
                $row = $ordered;
            }
        }
        $this->append($row);
    }

    /** @param iterable<iterable> $rows any iterable, including generators */
    public function writeRows(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->writeRow($row);
        }
    }

    /** Everything written so far, for a memory writer (stream null). */
    public function contents(): string
    {
        if ($this->stream !== null) {
            throw new \LogicException('contents() is only available on a writer without a stream');
        }
        return $this->buf;
    }

    /** Write the buffer to the stream. */
    public function flush(): void
    {
        if ($this->stream === null || $this->buf === '') {
            return;
        }
        $len = strlen($this->buf);
        for ($o = 0; $o < $len;) {
            $w = fwrite($this->stream, $o ? substr($this->buf, $o) : $this->buf);
            if ($w === false || $w === 0) {
                throw new \RuntimeException('Write error');
            }
            $o += $w;
        }
        $this->buf = '';
    }

    /** Flush and, if the writer opened the file itself, close it. Called automatically on destruction. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->flush();
        if ($this->owns) { fclose($this->stream); }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function append(array $row): void
    {
        $d = $this->dialect;
        $D = $d->delimiter;
        $E = $d->enclosure;
        $mode = $d->quoting;
        $single = count($row) === 1;
        $out = [];
        foreach ($row as $v) {
            $num = false;
            if (!is_string($v)) {
                if (is_int($v)) { $num = true; $v = (string) $v; }
                elseif (is_float($v)) { $num = true; $v = var_export($v, true); }
                elseif ($v === null) { $v = ''; }
                elseif (is_bool($v)) { $v = $v ? '1' : ''; }
                elseif ($v instanceof \Stringable) { $v = (string) $v; }
                else { throw new \TypeError('CSV field must be scalar, null or Stringable, ' . get_debug_type($v) . ' given'); }
            }
            $need = $v === ''
                ? $single
                : (($this->special !== '' && strcspn($v, $this->special) !== strlen($v)) || ($this->extra && $this->needsMore($v))); // (empty mask: see Scanner)
            if (!$this->started) { // a leading BOM would be swallowed by the reader
                $this->started = true;
                $need = $need || str_starts_with($v, "\xEF\xBB\xBF");
            }
            if ($need && $mode === Quoting::NONE) {
                throw new \UnexpectedValueException('Field needs quoting but the dialect has Quoting::NONE: ' . ($v === '' ? '(single empty field)' : var_export($v, true)));
            }
            if ($need || $mode === Quoting::ALL || ($mode === Quoting::NONNUMERIC && !$num)) {
                $v = $E . str_replace($E, $E . $E, $v) . $E;
            }
            $out[] = $v;
        }
        $this->buf .= implode($D, $out) . $d->lineTerminator;
        if ($this->stream !== null && strlen($this->buf) >= $this->bufferSize) {
            $this->flush();
        }
    }

    /** The less common reasons to enclose a field: multibyte tokens, a dangling token prefix, leading space. */
    private function needsMore(string $v): bool
    {
        foreach ($this->multi as $t) {
            if (str_contains($v, $t)) { return true; }
        }
        foreach ($this->tailBad as $t) {
            if (str_ends_with($v, $t)) { return true; }
        }
        return $this->dialect->skipInitialSpace && $v[0] === ' ';
    }
}
