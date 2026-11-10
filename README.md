# charm/csv

CSV encoding and decoding in plain PHP (8.2+, no dependencies, no extension): a readonly `Format`,
an `Encoder` that turns one row into one record, and an incremental `Decoder` that accepts input in chunks
of any size. There is no stream, file or generator handling in the package; you read and write with
`fread()` / `fwrite()` (or any other source and sink) and let the codec do only the CSV.

The `Decoder` is built for speed. It does not loop over characters: `strcspn()`, `strpos()` and
`explode()` find field and record boundaries in C, so PHP runs once per field (once per line for lines
without quotes). See [Performance](#performance) for measured numbers.

The API shape is the one proposed for ext/csv on php internals (a `Format` value object, an `Encoder`, a
`Decoder`, a `Quoting` enum). Its sibling charm/jsonl has the same
method names (`encode()`, `decode()`, `finish()`), without a shared interface.

```
composer require charm/csv
```

## Usage

```php
use Charm\Csv\{Decoder, Encoder, Format, Mode};

// Decode a stream: read, decode, and call finish() at the end
$decoder = new Decoder(header: true);
$h = fopen('users.csv', 'rb');
while (!feof($h)) {
    foreach ($decoder->decode(fread($h, 65536)) as $user) {   // ['id' => '1', 'name' => 'Ann']
        // ...
    }
}
foreach ($decoder->finish() as $user) {                        // the last record, if the file has no final newline
    // ...
}

// Decode a string
$decoder = new Decoder();
$rows = [...$decoder->decode("a,b\r\n1,\"x,y\"\r\n"), ...$decoder->finish()];   // [['a','b'], ['1','x,y']]

// Encode to a stream
$encoder = new Encoder(Format::excel(), header: true);         // BOM + CRLF, header from the first row's keys
$out = fopen('out.csv', 'wb');
foreach ($rows as $row) {                                      // ['id' => 1, 'name' => 'Ann']
    fwrite($out, $encoder->encode($row));
}

// Encode to a string
(new Encoder())->encode(['a', 'b,c']);                         // "a,\"b,c\"\r\n"

// Other formats
$format = Format::rfc4180()->with(delimiter: ';');
$decoder = new Decoder($format, mode: Mode::Lax);
```

## API

```php
namespace Charm\Csv;

enum Quoting { case Minimal; case All; case NonNumeric; case Never; }
enum Mode { case Strict; case Lax; }

final readonly class Format
{
    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public string $eolSequence = "\r\n",
        public Quoting $quoting = Quoting::Minimal,
        public bool $skipInitialSpace = false,
        public bool $bom = false,
        public ?string $null = null,
    ) {}
    public static function rfc4180(): self;   // the defaults
    public static function excel(): self;     // rfc4180 + UTF-8 BOM when encoding
    public static function tsv(): self;       // tab, LF, Quoting::Never
    public static function unix(): self;      // rfc4180 with LF
    public function with(mixed ...$changes): self;
}

final class Encoder
{
    public function __construct(Format $format = new Format(), bool|array $header = false);
    public function encode(array $row): string;            // one record, including the EOL sequence
}

final class Decoder
{
    public function __construct(Format $format = new Format(), bool|array $header = false, Mode $mode = Mode::Strict);
    public function decode(string $chunk): array;          // records completed by this chunk
    public function finish(): array;                       // end of input: last record, validation; resets the decoder
}

final class ParseException extends \RuntimeException {}    // public readonly int $lineNumber, $column, $record
```

### Format

| Option | Default | Meaning |
|---|---|---|
| `delimiter` | `,` | Any non-empty byte string, e.g. `;`, `\t`, `→`, `\|\|` |
| `enclosure` | `"` | Any non-empty byte string that does not overlap itself; escaped by doubling. There is no escape character |
| `eolSequence` | `\r\n` | Written after each record. When decoding, `\r\n`, `\n` and `\r` are all accepted if this is one of them; any other sequence (`\x1e`, `<>`) is matched exactly |
| `quoting` | `Quoting::Minimal` | `Minimal`; `All`; `NonNumeric` (PHP ints and floats stay bare); `Never` (the Encoder throws `UnexpectedValueException` if a field would need quoting; the Decoder treats quotes as literal text) |
| `skipInitialSpace` | `false` | Decoding: ignore spaces at the start of a field. The Encoder then encloses fields that start with a space |
| `bom` | `false` | Encoding: start with a UTF-8 BOM. The Decoder always strips a BOM at the start of the input |
| `null` | `null` | Text that stands for null, e.g. `'\N'`. The Encoder writes null as this text, the Decoder returns null for fields equal to it. A string equal to it reads back as null, so choose text your data does not contain |

A `Format` with empty tokens, or with a delimiter, enclosure or EOL sequence that equals or contains another, or an
enclosure that overlaps itself, throws `ValueError`. Multibyte tokens are supported (UTF-8 is self-synchronising, so
byte search is safe). A multibyte delimiter that overlaps itself (`||`) is allowed and matched left to right, so `a|||b`
decodes as `a`, `|b`; the Encoder encloses a field ending in `|` so its output always decodes back to the same values.

### Decoder

* **Chunks.** `decode()` takes any piece of the input and returns the records it completed. A record that is not provably
  complete at the end of a chunk (including a lone `\r`, a closing quote, or half of a multibyte token) is kept and parsed again when more
  input arrives, so where you cut the input never changes the result. A record is returned as soon as it is known to be complete; the
  first three bytes of the input are held back until they are known not to be a BOM.
  `finish()` returns the last record (input that does not end with an EOL), throws on an unterminated enclosure in `Mode::Strict`, and
  resets the decoder so it can be used again.
* **Blank lines** are skipped. A record with a single empty field must therefore be written as `""` (the Encoder does). A record with no fields cannot be represented.
* **Header.** `false`: records are lists of strings. `true`: the first record names the columns and the following records are
  associative arrays. A list of names: every record is associative with those names, and the first record is data. PHP turns
  numeric-string names such as `"1"` into integer keys.
* **Mode::Strict** (default) throws `ParseException` on malformed input and on a record whose field count differs from the first record's (or from the header's).
  **Mode::Lax** recovers the way `fgetcsv()` does and does not check field counts. With a header, a short record is padded with `null` and a long one
  keeps its extra fields under integer keys (their position): `['a' => '1', 'b' => '2', 2 => '3']`.
* **Header names.** Strict throws on empty or duplicate names in the header record; Lax renames them `column_N`, `name_2`, `name_3`. Names you pass as a list must be non-empty and unique (`ValueError`).

| Malformed input | `Mode::Strict` | `Mode::Lax` |
|---|---|---|
| Unterminated enclosure | `ParseException` at the opening quote (from `finish()`) | the rest of the input is the field |
| Text after a closing quote: `"ab"cd,` | `ParseException` | appended to the field up to the next delimiter: `abcd` |
| Quote inside an unquoted field: `5" pipe` | `ParseException` | literal |
| Space before an opening quote: `a, "b"` | `ParseException` (use `skipInitialSpace`) | the field ` "b"` |
| Field count differs from the first record | `ParseException` | accepted as it is |

"Lax" is this library's own definition, chosen to match what `fgetcsv()` does on these inputs.
`ParseException` has public readonly `lineNumber` (1-based, counts `\r\n`, `\n` and `\r` over the whole input, also inside quoted fields and across chunks),
`column` (1-based, UTF-8 characters) and `record` (1-based; a header is record 1; blank lines count as lines, not records).
After a `ParseException` discard the decoder.

Limit: a record that is still incomplete is parsed again whenever more input arrives, so a single record of N bytes fed in tiny chunks costs
O(N²/chunk). Feed chunks of a few KiB or more (the stream loop above uses 64 KiB). A record is held in memory whole.

### Encoder

* **Field values.** Strings, ints, floats (written round-trippably and independent of the locale, so `1.0` is `1.0`), `true` as `1`, `false` as `0`, `null`
  as an empty field (or `Format::$null`), and backed enums as their value. Anything else (arrays, resources, pure enums, objects, including `Stringable`) throws `TypeError`.
* **Enclosing.** A field is enclosed when it contains the delimiter, the enclosure or a line break; when it ends with a fragment of a multibyte token that could combine
  with the following delimiter or EOL; when it is the only field of a record and empty; when `skipInitialSpace` is on and it starts with a space; and when it is the first field
  of the output and starts with a BOM. `All` and `NonNumeric` enclose more; `Never` throws instead of enclosing.
* **Header.** `true`: the first `encode()` call also emits a header record from that row's keys (the row must be associative). A list: that header is emitted first.
  In both cases later associative rows are written in the header's column order, by key: missing keys become null, keys that are not in the header throw `ValueError`.
  List rows are written as they are. Without a header, rows are written as they are, whatever their keys.
* **Round trip.** For any rows of strings with at least one field, `decode(encode(rows)) === rows` in every format (with `Never`: whenever the Encoder accepts the rows).
  A property test checks this over 16 formats, including multibyte and self-overlapping tokens, random chunkings and both modes. A row with no fields is written as a blank line, which the Decoder skips.

## Performance

Measured on PHP 8.5.12, Linux 6.8, Intel Xeon E5-2697 v3 @ 2.6 GHz, 200 000 rows x 8 columns, best of 9 runs after 2 warm-up runs
(the median is within a few percent except where noted). The data is generated by `bench/bench.php` (git-ignored, in `bench/data/`):
"plain" has no quotes (14 MB); "messy" has quoted fields, doubled quotes, embedded newlines and commas in 1 of 5 fields, so most lines contain
a quote (16.5 MB). Every row is read through `fread(…, 65536)` + `decode()` + `finish()`, from a file. "collect" builds the complete result array
(for every method in the table), "stream" counts the rows and discards them.

Command (starts the benchmark with the tracing JIT, then without it, and checks that the JIT compiled something):

```
composer install
php bench/run.php                      # --rows=50000 --runs=3 for a quick run
```

which runs `php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 bench/bench.php`
and the same with `-d opcache.jit_buffer_size=0` (opcache on, JIT off).

**With the tracing JIT** (`opcache.jit=1255`)

| Decoding | plain ms (MB/s) | vs `fgetcsv` | messy ms (MB/s) | vs `fgetcsv` |
|---|---:|---:|---:|---:|
| `fgetcsv()` loop, collect | 1102 (13) | 1.0x | 1172 (14) | 1.0x |
| `str_getcsv()` per line, collect | 1084 (13) | 1.0x | n/a (embedded newlines) | |
| `Decoder` (Lax), collect | 89 (157) | 12.4x | 256 (65) | 4.6x |
| `Decoder` (Lax), stream | 98 (143) | 11.3x | 252 (66) | 4.7x |
| `Decoder` (Strict), stream | 106 (132) | 10.4x | 258 (64) | 4.5x |
| `Decoder` (Lax, `header: true`), stream | 160 (87) | 6.9x | 323 (51) | 3.6x |

| Encoding to `php://memory` | plain ms | messy ms |
|---|---:|---:|
| `fputcsv()` loop | 106 | 103 |
| `Encoder` + `fwrite()` | 154 (0.69x of `fputcsv` speed) | 253 (0.41x) |

**Without the JIT** (opcache on, `opcache.jit_buffer_size=0`)

| Decoding | plain ms (MB/s) | vs `fgetcsv` | messy ms (MB/s) | vs `fgetcsv` |
|---|---:|---:|---:|---:|
| `fgetcsv()` loop, collect | 1064 (13) | 1.0x | 1133 (15) | 1.0x |
| `str_getcsv()` per line, collect | 1079 (13) | 1.0x | n/a | |
| `Decoder` (Lax), collect | 116 (120) | 9.2x | 375 (44) | 3.0x |
| `Decoder` (Lax), stream | 124 (112) | 8.6x | 383 (43) | 3.0x |
| `Decoder` (Strict), stream | 133 (105) | 8.0x | 426 (39) | 2.7x |
| `Decoder` (Lax, `header: true`), stream | 189 (74) | 5.6x | 465 (36) | 2.4x |

| Encoding to `php://memory` | plain ms | messy ms |
|---|---:|---:|
| `fputcsv()` loop | 100 | 119 |
| `Encoder` + `fwrite()` | 178 (0.56x) | 362 (0.33x) |

Without the JIT the numbers differ: the decoder is 25-45% slower than with it, while `fgetcsv()` and `fputcsv()`, which are native code, do not change.
Your numbers will depend on the PHP build, the CPU and the data; the machine this was measured on was not idle, so differences of 10-15% between runs are noise.
Timings include building the result arrays, so the cycle collector is part of them (it is why "messy" is slower than its extra bytes suggest).
`fputcsv()` also encloses fields that contain a space or a tab, so its output is slightly larger than the `Encoder`'s.

Why the decoder is fast, and where it stops being fast: a line that contains no enclosure is split with a single `explode()`; lines with quotes go through a
per-field scanner (`strcspn()` for plain fields, `strpos()` for quoted ones). The more quoted fields, the less of the work happens in one C call.
Headers cost an `array_combine()` per record.


## Limitations

* Pure PHP: it will not match native code, and I have not measured it against a build of the proposed ext/csv.
* Quote-heavy data decodes 2-5x faster than `fgetcsv()`, not 10x: the more quoted fields, the less work is done in one C call.
* The Encoder is slower than `fputcsv()` (see the table); the Decoder is where the time was spent.
* Bytes in, bytes out: no charset conversion. UTF-8 is assumed only for the BOM and for `column` in error positions.
* No comment lines, no escape character, no formula-injection protection (prefixing `=`, `+`, `-`, `@` is up to you).
* Streams are the caller's job; reading from non-blocking streams needs the caller to skip empty reads.

## Tests

```
composer install
vendor/bin/phpunit
```

RFC 4180 cases, every strict-mode error with its line, column and record, lax recovery, header and null handling, the BOM,
every cut of the test documents into two chunks and every chunk size from 1 to 17 (plus random chunkings, and three-way cuts of a multibyte
document), a fuzz test against a deliberately naive byte-by-byte reference parser (`tests/ReferenceParser.php`) in nine formats,
seeded round-trip property tests, comparison with `fgetcsv()` on generated samples in `tests/fixtures`, and the encoder's type conversions.
CI runs PHP 8.2-8.5 with deprecations and notices as failures.

## License

MIT
