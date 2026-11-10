<?php

declare(strict_types=1);

namespace FrodeBorli\Csv;

/**
 * Lazy CSV reader over a string, a file path or a stream resource.
 *
 *     foreach (Reader::fromFile('data.csv') as $row) { ... }                       // lists
 *     foreach (Reader::fromFile('data.csv')->withHeader() as $row) { ... }         // ['id' => '1', ...]
 *     $cols = Reader::fromFile('data.csv')->withHeader()->columns();               // ['id' => [...], ...]
 *
 * Iteration is lazy (one chunk of rows in memory at a time). A string or file reader can be
 * iterated repeatedly; a stream reader reads from the stream's current position, once.
 * Iterating yields sequential integer keys across the whole input.
 *
 * Blank lines are skipped. A record with a single empty field is therefore written as `""`.
 * Header and field-count handling: see withHeader() and requireConsistentFields().
 */
final class Reader implements \IteratorAggregate
{
    private const BOM = "\xEF\xBB\xBF";

    private int $chunkSize = 1 << 18;
    private bool|array $header = false;
    private array $casts = [];
    private bool $consistent = false;

    /** @param string|resource $source */
    private function __construct(private string $kind, private mixed $source, private Dialect $dialect)
    {
    }

    public static function fromString(string $csv, ?Dialect $dialect = null): self
    {
        return new self('string', $csv, $dialect ?? new Dialect());
    }

    /** The file is opened when iteration starts and closed when it ends (or the generator is destroyed). */
    public static function fromFile(string $path, ?Dialect $dialect = null): self
    {
        return new self('file', $path, $dialect ?? new Dialect());
    }

    /** @param resource $stream Not closed by the reader. */
    public static function fromStream($stream, ?Dialect $dialect = null): self
    {
        if (!is_resource($stream)) {
            throw new \ValueError('Reader::fromStream() expects a stream resource');
        }
        return new self('stream', $stream, $dialect ?? new Dialect());
    }

    /** Bytes read per chunk (default 256 KiB). Results never depend on it; only speed and memory do. */
    public function withChunkSize(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \ValueError('Chunk size must be at least 1');
        }
        $c = clone $this;
        $c->chunkSize = $bytes;
        return $c;
    }

    /**
     * true: the first record holds the column names and rows become ['name' => value].
     * list<string|int>: use these names; the first record is data.
     * false: rows are plain lists.
     *
     * Empty or duplicate names: strict throws ParseException; lax renames them (`column_N`, `name_2`).
     * A row whose width differs from the header: strict throws; lax pads with null / drops extra fields.
     * Note PHP turns numeric-string names like "1" into int keys.
     */
    public function withHeader(bool|array $header = true): self
    {
        $c = clone $this;
        $c->header = $header;
        return $c;
    }

    /**
     * Callables applied to columns, keyed by index or (with a header) name: `[0 => intval(...), 'price' => floatval(...)]`.
     * Missing (null) values are left alone.
     */
    public function withCasts(array $casts): self
    {
        $c = clone $this;
        $c->casts = $casts;
        return $c;
    }

    /** Throw ParseException when a row has a different number of fields than the first row (list mode; header modes follow withHeader()). */
    public function requireConsistentFields(bool $on = true): self
    {
        $c = clone $this;
        $c->consistent = $on;
        return $c;
    }

    /** @return \Generator<int, array> */
    public function getIterator(): \Generator
    {
        $names = null;
        $cast = [];
        $k = 0;
        $headerMode = $this->header !== false;
        $given = is_array($this->header) ? $this->checkNames($this->header) : null;
        $first = true;
        foreach ($this->chunks($headerMode ? $this->dialect->strict : $this->consistent, $given === null ? null : count($given)) as $rows) {
            if ($first) {
                $first = false;
                if ($headerMode) {
                    $names = $given ?? $this->namesFrom(array_shift($rows));
                    $k = count($names);
                }
                $cast = $this->resolveCasts($names);
                if (!$rows) { continue; }
            }
            foreach ($rows as $row) {
                if ($cast) {
                    foreach ($cast as $i => $f) {
                        if (isset($row[$i])) { $row[$i] = $f($row[$i]); }
                    }
                }
                if ($names === null) {
                    yield $row;
                    continue;
                }
                $c = count($row);
                if ($c !== $k) { // only reachable when lax (strict is checked by the scanner)
                    $row = $c < $k ? array_pad($row, $k, null) : array_slice($row, 0, $k);
                }
                yield array_combine($names, $row);
            }
        }
    }

    /**
     * Column-major result: `['name' => [v1, v2, ...], ...]` with a header, otherwise a list of columns.
     *
     * Built chunk by chunk: only one chunk of rows exists at a time, and each column ends up
     * as one packed array. Width handling is as for withHeader(), also without a header
     * (the first row defines the width).
     *
     * @return array<int|string, list<mixed>>
     */
    public function columns(): array
    {
        $names = null;
        $k = null;
        $pieces = [];
        $cast = [];
        $first = true;
        $given = is_array($this->header) ? $this->checkNames($this->header) : null;
        foreach ($this->chunks($this->dialect->strict, $given === null ? null : count($given)) as $rows) {
            if ($first) {
                $first = false;
                if ($this->header !== false) {
                    $names = $given ?? $this->namesFrom(array_shift($rows));
                    $k = count($names);
                }
                $cast = $this->resolveCasts($names);
                if (!$rows) { continue; }
            }
            $k ??= count($rows[0]);
            for ($i = 0; $i < $k; $i++) {
                $piece = array_column($rows, $i);
                if (count($piece) !== count($rows)) { // a short row (strict mode cannot get here): pad lax rows, then redo
                    foreach ($rows as $j => $row) {
                        if (count($row) < $k) { $rows[$j] = array_pad($row, $k, null); }
                    }
                    $piece = array_column($rows, $i);
                }
                if (isset($cast[$i])) { // cast per chunk, so the strings never pile up
                    $piece = array_map(static fn($v) => $v === null ? null : ($cast[$i])($v), $piece);
                }
                $pieces[$i][] = $piece;
            }
        }
        $out = [];
        for ($i = 0; $i < ($k ?? 0); $i++) {
            $ps = $pieces[$i] ?? [[]];
            unset($pieces[$i]); // keep peak memory at "all columns + one column"
            $col = count($ps) === 1 ? $ps[0] : array_merge(...$ps);
            $out[$names[$i] ?? $i] = $col;
        }
        return $out;
    }

    /** All rows as an array (not lazy). */
    public function toArray(): array
    {
        return iterator_to_array($this, false);
    }

    /** @return array<int, callable> index => callable */
    private function resolveCasts(?array $names): array
    {
        $out = [];
        foreach ($this->casts as $key => $f) {
            $i = is_int($key) ? $key : ($names === null ? false : array_search($key, $names, true));
            if ($i === false) {
                throw new \ValueError("Cannot cast unknown column '$key'" . ($names === null ? ' (named casts need a header)' : ''));
            }
            $out[$i] = $f;
        }
        return $out;
    }

    /** User-supplied names must be usable as they are. */
    private function checkNames(array $names): array
    {
        $names = array_values($names);
        if (!$names || count(array_unique($names)) !== count($names) || in_array('', $names, true)) {
            throw new \ValueError('Header names must be non-empty and unique');
        }
        return $names;
    }

    /** Names from a header record: strict rejects empty/duplicate names, lax repairs them. */
    private function namesFrom(?array $raw): array
    {
        if ($raw === null) {
            return []; // empty input
        }
        $seen = [];
        foreach ($raw as $i => $name) {
            if ($name === '' || isset($seen[$name])) {
                $why = $name === '' ? 'empty column name' : "duplicate column name '$name'";
                if ($this->dialect->strict) {
                    throw new ParseException("$why (column " . ($i + 1) . ')', 1, 1, 1);
                }
                $base = $name === '' ? 'column_' . ($i + 1) : $name;
                $name = $base;
                for ($n = 2; isset($seen[$name]); $n++) {
                    $name = "{$base}_$n"; // name, name_2, name_3
                }
            }
            $seen[$name] = true;
            $raw[$i] = $name;
        }
        return $raw;
    }

    /** @return \Generator<int, list<list<string>>> one array of rows per chunk read */
    private function chunks(bool $check, ?int $expect): \Generator
    {
        $sc = new Scanner($this->dialect, $check);
        $sc->expect = $expect;
        $isString = $this->kind === 'string';
        $h = $this->source;
        if ($this->kind === 'file') {
            $h = @fopen($this->source, 'rb');
            if ($h === false) {
                throw new \RuntimeException("Cannot open '{$this->source}' for reading");
            }
        }
        try {
            $buf = '';
            $off = 0;
            $want = $this->chunkSize;
            $head = true;
            while (true) {
                if ($isString) {
                    $data = substr($this->source, $off, $want);
                    $off += strlen($data);
                    $final = $off >= strlen($this->source);
                } else {
                    $data = fread($h, $want);
                    if ($data === false) {
                        throw new \RuntimeException('Read error');
                    }
                    $final = feof($h);
                }
                $buf .= $data;
                if ($head) { // a BOM may arrive in pieces
                    if (strlen($buf) < 3 && !$final) { continue; }
                    $head = false;
                    if (str_starts_with($buf, self::BOM)) { $buf = substr($buf, 3); }
                }
                $rows = $sc->scan($buf, $final);
                $buf = $sc->consumed >= strlen($buf) ? '' : substr($buf, $sc->consumed);
                if ($rows) { yield $rows; }
                if ($final) { return; }
                // A record bigger than the chunk: grow the read size so rescans stay amortised-linear.
                $want = strlen($buf) > 4 * $this->chunkSize ? strlen($buf) : $this->chunkSize;
            }
        } finally {
            if ($this->kind === 'file') { fclose($h); }
        }
    }
}
