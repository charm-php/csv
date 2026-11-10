# frodeborli/csv

A CSV reader and writer in plain userspace PHP (8.2+, no dependencies, no extension).

PHP's own `fgetcsv()` parses at roughly 13 MB/s, and a CSV extension has been proposed for core
([RFC: CSV Extension](https://wiki.php.net/rfc/csv_extension), php-src PR #24199). This library
exists to show what userspace can do: it parses 7-10x faster than `fgetcsv()` on quote-free data
and 2-3x faster on heavily quoted data, with a feature set that covers the RFC's API surface and adds
streams, headers, column-major output, quoting modes and strict/lax parsing. It does not loop over
characters: `strcspn()`, `strpos()` and `explode()` find field and record boundaries in C, and PHP only
runs once per field (or once per line, for lines without quotes). Everything below is covered by tests
(see [Tests](#tests)) and the numbers are reproducible with one command (see [Benchmarks](#benchmarks)).

```
composer require frodeborli/csv
```

## Quick start

```php
use FrodeBorli\Csv\{Dialect, Quoting, Reader, Writer};

// Read: lazily, from a file path, a string or any stream resource
foreach (Reader::fromFile('data.csv') as $row) {            // ['1', 'Ann', 'ann@example.com']
}
$rows = Reader::fromString("a,b\r\n1,\"x,y\"\r\n")->toArray();  // [['a','b'], ['1','x,y']]

// Header row -> associative rows
foreach (Reader::fromFile('users.csv')->withHeader() as $user) {   // ['id' => '1', 'name' => 'Ann']
}

// Column-major, with optional casts (see "Output shapes")
$cols = Reader::fromFile('users.csv')->withHeader()
    ->withCasts(['id' => intval(...)])->columns();             // ['id' => [1, 2, ...], 'name' => ['Ann', ...]]

// Dialects
$d = Dialect::rfc4180()->with(delimiter: ';', strict: true);
$rows = Reader::fromFile('eu.csv', $d)->toArray();

// Write: rows, associative rows or generators; to a file, php://output or any stream
$w = Writer::toFile('out.csv', Dialect::excel());            // UTF-8 BOM + CRLF, for Excel
$w->writeRows([['id' => 1, 'name' => 'Ann'], ['id' => 2, 'name' => 'Bob']]);   // header derived from keys
$w->close();

header('Content-Type: text/csv');
$w = Writer::toStream(fopen('php://output', 'w'));           // HTTP download, flushed in 1 MiB writes
$w->writeHeader(['id', 'name']);
$w->writeRows($generatorOfRows);

// Strings
Writer::rowToString(['a', 'b,c']);                           // "a,\"b,c\"\r\n"
Writer::rowsToString($rows, Dialect::tsv());
```

### RFC-compatible functions

`array_to_row`, `row_to_array`, `collection_to_buffer`, `buffer_to_collection`,
`buffer_to_collection_lax` and `collection_to_file` exist in the `FrodeBorli\Csv` namespace with the
RFC's signatures (`$delimiter`, `$enclosure`, `$eolSequence`), so `use function FrodeBorli\Csv\buffer_to_collection;`
is a drop-in. `LazyLaxCollection::createFromBuffer()/createFromFile()` correspond to
`Reader::fromString()/fromFile()` (a `Reader` is an `IteratorAggregate` too).

## Dialects

`Dialect` is an immutable value object; derive variants with `with()`.

| Option | Default | Meaning |
|---|---|---|
| `delimiter` | `,` | Any non-empty byte string, e.g. `;`, `\t`, `→`, `\|\|` |
| `enclosure` | `"` | Any non-empty byte string that does not overlap itself; escaped by doubling |
| `lineTerminator` | `\r\n` | Written after each row. When reading, `\r\n`, `\n` and `\r` are all accepted if this is one of them; any other terminator (`\x1e`, `<>`) is matched exactly |
| `quoting` | `Quoting::MINIMAL` | `MINIMAL`, `ALL`, `NONNUMERIC` (PHP ints/floats stay bare), `NONE` (throws if a field would need quoting; when reading, quotes are literal text) |
| `skipInitialSpace` | `false` | Reading: ignore spaces at the start of a field. The writer then encloses fields that start with a space |
| `strict` | `false` | Reading: throw `ParseException` instead of recovering (see below) |
| `bom` | `false` | Writing: start with a UTF-8 BOM. A BOM is always stripped when reading |

Presets: `Dialect::rfc4180()`, `Dialect::excel()` (RFC 4180 + BOM; for `;` locales use `->with(delimiter: ';')`),
`Dialect::tsv()` (tab, LF, `Quoting::NONE`, as in IANA's text/tab-separated-values), `Dialect::unix()` (RFC 4180 with LF).

Invalid dialects throw `ValueError` when constructed: empty tokens; a delimiter, enclosure or terminator
that equals or contains another; an enclosure that overlaps itself (`||` cannot be escaped by doubling
unambiguously). Multibyte delimiters, enclosures and terminators are supported; UTF-8 is self-synchronising,
so byte search is safe. A multibyte delimiter that overlaps itself (`||`) is allowed, and tokens are matched left to
right, so `a|||b` reads as `a`, `|b`. The writer encloses such fields (a field ending in `|` gets quoted) so that
its output always reads back to the same values.

There is no escape character (as in the RFC; `fgetcsv()`'s `\` default is a well-known source of bugs),
and parsing never depends on the locale.

## Reading

```php
Reader::fromString(string $csv, ?Dialect $d = null)
Reader::fromFile(string $path, ?Dialect $d = null)    // opened lazily, closed when iteration ends
Reader::fromStream($resource, ?Dialect $d = null)     // read from its current position; never closed
```

Rows are produced lazily (one chunk of rows in memory at a time) in chunks of 256 KiB by default
(`->withChunkSize()`; 4 KiB to 256 KiB measured about equal, 4 MiB slower). The result never depends on the
chunk size: a record that is not provably complete at the end of a chunk is carried over and re-scanned with more
data, which is why a lone `\r`, a closing quote or half of a multibyte token at a chunk edge is handled correctly. The tests run
every fixture with chunk sizes 1 to 17 bytes, including multibyte tokens split at every byte. Records larger
than a chunk grow the read size geometrically, so they stay linear.

Blank lines are skipped. A row consisting of one empty field is therefore written as `""` by `Writer`.

### Output shapes

```php
$r = Reader::fromFile('users.csv');
$r->toArray();                         // [['1','Ann'], ...]                       row-major lists (also: foreach)
$r->withHeader()->toArray();           // [['id'=>'1','name'=>'Ann'], ...]         associative, first record is the header
$r->withHeader(['id','name'])          // ... or with given names; the first record is then data
$r->withHeader()->columns();           // ['id'=>['1',...], 'name'=>['Ann',...]]   column-major
$r->columns();                         // [['id','1',...], ['name','Ann',...]]     column-major, no header: a list of columns
$r->withCasts(['id' => intval(...), 2 => floatval(...)])   // by name (needs a header) or index; null stays null
```

Column-major output is built chunk by chunk with `array_column()` (no full row array ever exists; only one chunk's rows at a
time) and every column is one packed array. The memory saving is real but depends on the data. Measured on this
machine, peak memory above baseline, 200 000 rows:

| Data | Shape | Peak MB |
|---|---|---:|
| 8 text columns, 14 MB file | `fgetcsv()` rows array | 105.6 |
| | `Reader` rows | 114.8 |
| | `Reader` associative rows | 147.5 |
| | `Reader` columns | 104.8 |
| | `Reader` lazy `foreach` | 5.0 |
| 5 integer columns, 5.7 MB file | associative rows (strings) | 113.4 |
| | columns (strings) | 66.6 |
| | columns with `intval` casts | 35.8 |

For text columns the strings dominate and column-major saves little over rows (it avoids the per-row array and, versus
associative rows, the per-row hash table: about 29% less). It pays off for numeric columns, where casting turns 32-byte-plus string allocations into 16-byte ints (-68% vs
associative rows), and for many-column data. Building columns costs time (about 1.8x the row-major time here).
If you can process rows as they arrive, lazy iteration beats all of them. Note that PHP turns numeric-string
keys such as `"1"` into integer keys.

### Headers

* A row whose width differs from the header: strict throws `ParseException`; lax pads with `null` or drops extra fields.
* Empty or duplicate header names: strict throws; lax renames them `column_N`, `name_2`, `name_3`.
* Names you pass to `withHeader([...])` must be non-empty and unique (`ValueError`).
* `->requireConsistentFields()` makes list mode throw when a row's width differs from the first row's.

### Strict vs lax

Both modes parse valid RFC 4180 input identically. They differ only on malformed input. "Lax" is this library's own
definition, chosen to match what `fgetcsv()` does on these cases (it is not the RFC's lax mode, which concerns row width):

| Malformed input | Strict (`strict: true`) | Lax (default) |
|---|---|---|
| Unterminated quoted field | `ParseException` at the opening quote | the rest of the input is the field |
| Text after a closing quote: `"ab"cd,` | `ParseException` | appended to the field up to the next delimiter: `abcd` |
| Quote inside an unquoted field: `5" pipe` | `ParseException` | literal |
| Space before an opening quote: `a, "b"` | the same as the line above (use `skipInitialSpace`) | field ` "b"` |
| Row width differs from the header | `ParseException` | pad with `null` / drop extras |

`ParseException` has public readonly `csvLine`, `column` (1-based, UTF-8 characters) and `record` (1-based, header
is record 1; blank lines count as lines, not records). Lines count `\r\n`, `\n` and `\r`, including those inside quoted fields.

## Writing

```php
$w = Writer::toFile('out.csv', $dialect);            // or Writer::toStream($res, $dialect), new Writer(null) for memory
$w->writeHeader(['id', 'name']);                     // optional; fixes the column order for associative rows
$w->writeRow(['1', 'Ann']);                          // lists are written as they are
$w->writeRow(['name' => 'Bob', 'id' => 2]);          // associative rows follow the header; missing keys are empty,
$w->writeRows($anyIterableOrGenerator);              //   unknown keys throw ValueError
$w->close();                                         // flush; also done on destruction
```

Rows are encoded into a buffer that is flushed in writes of at least 1 MiB (configurable), never per field.
Values may be strings, ints, floats (round-trip exact, locale independent, so `1.0` is written as `1.0`), bools
(`1` or empty), `null` (empty) and `Stringable`; anything else throws `TypeError`.

A field is enclosed when it contains the delimiter, the enclosure or a line break, when it ends with a fragment of a
multibyte token that could combine with the following delimiter or terminator, when it is the only field of a
row and empty, when `skipInitialSpace` is on and it starts with a space, and when it is the very first field and starts with a BOM.
`Quoting::NONE` throws `UnexpectedValueException` in those cases rather than writing something that cannot be read back.

**Round trip:** for any list of rows, each with at least one string field, `read(write(rows)) === rows` in every dialect (with `NONE`:
whenever the writer accepts the rows). This is checked with a seeded property test over 14 dialects, including multibyte and
self-overlapping tokens, many chunk sizes, in lax and strict mode. A row with no fields is written as a blank line, which readers skip.

## Compared with ext/csv and fgetcsv

Based on my reading of the RFC text; check the RFC for the final word on ext/csv.

| | frodeborli/csv | ext/csv (RFC) | `fgetcsv()` |
|---|---|---|---|
| Requires | PHP 8.2+, userspace | PHP core (proposed) | PHP core |
| RFC 4180 by default, no escape character | yes | yes | no (`\` escape unless disabled) |
| Locale independent | yes | yes | not guaranteed |
| Multibyte delimiter / enclosure / terminator | yes | yes | single byte (terminator not configurable) |
| Rejects ambiguous dialects with `ValueError` | yes | yes | no |
| Strict and lax parsing | strict throws with line/column/record; lax recovers as documented | strict `ValueError`; lax variant allows ragged rows | lax only |
| Lazy iteration from file / string | yes, generators; any stream resource | `LazyLaxCollection` over a file or buffer | line by line |
| Row-major lists | yes | yes | yes |
| Associative rows / header handling | yes | not in the proposed API | no |
| Column-major output, per-column casts | yes | not in the proposed API | no |
| Quoting modes (ALL, NONNUMERIC, NONE) | yes | not in the proposed API (minimal enclosure) | `fputcsv` only encloses as needed |
| BOM on read / write, `skipInitialSpace`, presets | yes | not in the proposed API | no |
| Writes to a file / any stream / php://output | yes, buffered | file or string | `fputcsv` per row |
| Round trip guaranteed for string rows | yes (tested) | designed to | no |
| Speed | 7-10x `fgetcsv()` on plain data (see below) | not measured here; native code should be faster | baseline, ~13 MB/s |

## Benchmarks

PHP 8.5.12, Linux 6.8, Intel Xeon E5-2697 v3 @ 2.6 GHz. 200 000 rows x 8 columns, generated by `bench/bench.php`
(best of 3 runs; includes building the complete result array unless stated). "plain" has no quotes (14.0 MB);
"messy" has quoted fields, doubled quotes, embedded newlines and commas in 1 of 5 fields, so 83% of lines contain a quote (16.5 MB).

| Reading | plain, no JIT | plain, tracing JIT | messy, no JIT | messy, tracing JIT |
|---|---:|---:|---:|---:|
| `fgetcsv()` loop | 1072 ms (13.0 MB/s) | 1067 ms (13.1) | 1305 ms (12.7) | 1297 ms (12.7) |
| `str_getcsv()` per line | 1075 ms (13.0) | 1066 ms (13.1) | n/a (embedded newlines) | n/a |
| `Reader` rows, `toArray()` | 146 ms (95.6 MB/s), **7.3x** | 115 ms (121.7), **9.3x** | 628 ms (26.3), **2.1x** | 449 ms (36.8), **2.9x** |
| `Reader` rows, lazy `foreach` | 133 ms (105.5), 8.1x | 100 ms (139.3), 10.6x | 469 ms (35.2), 2.8x | 267 ms (62.0), 4.9x |
| `Reader` strict, `toArray()` | 145 ms, 7.4x | 114 ms, 9.4x | 675 ms, 1.9x | 490 ms, 2.7x |
| `Reader` associative rows | 208 ms, 5.2x | 173 ms, 6.2x | 730 ms, 1.8x | 549 ms, 2.4x |
| `Reader` columns | 259 ms, 4.1x | 230 ms, 4.6x | 795 ms, 1.6x | 601 ms, 2.2x |

| Writing 200 000 rows to a file | plain, no JIT | plain, JIT | messy, no JIT | messy, JIT |
|---|---:|---:|---:|---:|
| `fputcsv()` loop | 1129 ms | 1095 ms | 1290 ms | 1276 ms |
| `Writer::toFile` | 403 ms, 2.8x | 206 ms, 5.3x | 587 ms, 2.2x | 400 ms, 3.2x |

JIT here means `php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M`; "no JIT" is stock CLI with opcache off.
`toArray()` is slower than lazy iteration on the messy data because building 200 000 arrays triggers PHP's cycle collector; `fgetcsv()` pays the same.

How it is fast, and where it stops being fast: a line that contains no enclosure is split with a single `explode()`; lines with
quotes go through a per-field scanner (`strcspn()` for plain fields, `strpos()` for quoted ones). The more quoted fields,
the less of the work is done in one C call, hence 7x on plain data and 2x on this deliberately heavy messy data. Real-world files with a few
quoted columns land in between. I have not benchmarked against a build of the proposed ext/csv; a native
implementation should be faster than any userspace code, and what this shows is the distance to `fgetcsv()`, not to it.

Reproduce:

```
composer install
php bench/bench.php                                   # data is generated in bench/data/ (git-ignored)
php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M bench/bench.php
php bench/bench.php --rows=50000 --runs=1             # quick run
```

## Limitations

* Pure PHP: it will not match native code.
* Quote-heavy data is 2-3x faster than `fgetcsv()`, not 8x (see above).
* A single record is held in memory whole (a 100 MB quoted field needs a 100 MB buffer).
* Blank lines are skipped, so a single-column file with genuinely empty values must quote them (`""`), as `Writer` does. A row with no fields cannot be represented.
* The "lax" semantics are this library's own (documented above); input that is malformed in other ways may be read differently by other parsers.
* Bytes in, bytes out: no charset conversion. UTF-8 is assumed only for the BOM and for the `column` in error positions.
* No comment lines, no escape character, no formula-injection protection (prefixing `=`, `+`, `-`, `@` is up to you).
* Reading from non-blocking streams is not supported (an empty read that is not EOF would spin).
* An enclosure that overlaps itself (`||`) is rejected; a delimiter/terminator that does is accepted and matched left to right.

## Tests

```
composer install
vendor/bin/phpunit
```

The suite covers RFC 4180 cases, every strict-mode error with its line/column/record, lax recovery, chunk-boundary
equivalence for chunk sizes 1-17 (strings and streams, multibyte tokens split mid-character), a fuzz test against a
deliberately naive byte-by-byte reference parser (`tests/ReferenceParser.php`) in nine dialects, a seeded round-trip property test, comparison with
`fgetcsv()` on generated samples in `tests/fixtures`, output shapes, and writing to `php://memory` and `php://output`.
CI runs PHP 8.2-8.5 with deprecations and notices as failures.

## License

MIT
