<?php

declare(strict_types=1);

namespace FrodeBorli\Csv;

/**
 * The format of a CSV stream. Immutable; use with() to derive variants:
 * `Dialect::rfc4180()->with(delimiter: ';', strict: true)`.
 *
 * Reading: if lineTerminator is "\r\n", "\n" or "\r", all three are accepted as record
 * terminators ("universal newlines"). Any other terminator is matched exactly.
 * Writing always uses lineTerminator. A UTF-8 BOM is always stripped on reading;
 * `bom` makes writers emit one.
 */
final readonly class Dialect
{
    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public string $lineTerminator = "\r\n",
        public Quoting $quoting = Quoting::MINIMAL,
        /** Reading: ignore spaces directly after a delimiter (and at the start of a record). */
        public bool $skipInitialSpace = false,
        /** Reading: throw ParseException on malformed input instead of recovering. */
        public bool $strict = false,
        public bool $bom = false,
    ) {
        $tokens = ['delimiter' => $delimiter, 'enclosure' => $enclosure, 'lineTerminator' => $lineTerminator];
        foreach ($tokens as $name => $t) {
            if ($t === '') {
                throw new \ValueError("Dialect $name must not be empty");
            }
        }
        // No token may contain another: that is what makes a dialect ambiguous.
        // (The standard terminators contain each other by design, so they are only compared against the others.)
        $eols = $this->eols();
        $pairs = [['delimiter', $delimiter, 'enclosure', $enclosure]];
        foreach ($eols as $eol) {
            $pairs[] = ['delimiter', $delimiter, 'lineTerminator', $eol];
            $pairs[] = ['enclosure', $enclosure, 'lineTerminator', $eol];
        }
        foreach ($pairs as [$na, $a, $nb, $b]) {
            if (str_contains($a, $b) || str_contains($b, $a)) {
                throw new \ValueError("Ambiguous dialect: $na and $nb overlap");
            }
        }
        // An enclosure that overlaps itself (e.g. "||") cannot be escaped by doubling unambiguously.
        for ($k = 1; $k < strlen($enclosure); $k++) {
            if (substr($enclosure, 0, $k) === substr($enclosure, -$k)) {
                throw new \ValueError('Ambiguous dialect: enclosure overlaps itself');
            }
        }
        if ($skipInitialSpace && ($delimiter[0] === ' ' || $enclosure[0] === ' ')) {
            throw new \ValueError('skipInitialSpace cannot be combined with a delimiter or enclosure starting with a space');
        }
    }

    /** RFC 4180: comma, double quote, CRLF. Parsing is lax unless strict is set. */
    public static function rfc4180(): self
    {
        return new self();
    }

    /** RFC 4180 plus a UTF-8 BOM when writing, which Excel needs to detect UTF-8. For ';' locales: ->with(delimiter: ';'). */
    public static function excel(): self
    {
        return new self(bom: true);
    }

    /** Tab separated, LF, no quoting at all (IANA text/tab-separated-values): writing throws on tabs/newlines in data. */
    public static function tsv(): self
    {
        return new self("\t", '"', "\n", Quoting::NONE);
    }

    /** Like RFC 4180 but with LF line endings. (Python's "unix" dialect quotes everything; this does not.) */
    public static function unix(): self
    {
        return new self(lineTerminator: "\n");
    }

    /** A copy with the given constructor arguments replaced. */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** True if the terminator is one of "\r\n", "\n", "\r" (all three are then accepted when reading). */
    public function universalEol(): bool
    {
        return $this->lineTerminator === "\r\n" || $this->lineTerminator === "\n" || $this->lineTerminator === "\r";
    }

    /** @return list<string> the terminators recognised when reading */
    public function eols(): array
    {
        return $this->universalEol() ? ["\r\n", "\n", "\r"] : [$this->lineTerminator];
    }
}
