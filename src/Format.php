<?php

declare(strict_types=1);

namespace Charm\Csv;

/**
 * The format of a CSV stream. Immutable; use with() to derive variants:
 * `Format::rfc4180()->with(delimiter: ';')`.
 *
 * Decoding: if eolSequence is "\r\n", "\n" or "\r", all three are accepted as record
 * terminators ("universal newlines"). Any other sequence is matched exactly.
 * Encoding always uses eolSequence. The Decoder always strips a leading UTF-8 BOM;
 * `bom` makes the Encoder emit one.
 *
 * There is no escape character: an enclosure inside an enclosed field is doubled (RFC 4180).
 */
final readonly class Format
{
    /** @throws \ValueError if a token is empty, a token equals or contains another, or the enclosure overlaps itself */
    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public string $eolSequence = "\r\n",
        public Quoting $quoting = Quoting::Minimal,
        /** Decoding: ignore spaces directly after a delimiter (and at the start of a record). Encoding: enclose fields that start with a space. */
        public bool $skipInitialSpace = false,
        /** Encoder: emit a UTF-8 BOM before the first record (Excel needs it to detect UTF-8). The Decoder always strips one. */
        public bool $bom = false,
        /**
         * Optional text standing for null, to tell null from "" in a round trip. The Encoder writes null as this text
         * (instead of ""), the Decoder returns null for every field equal to it. A string field that equals it
         * therefore reads back as null: pick text your data never contains.
         */
        public ?string $null = null,
    ) {
        $tokens = ['delimiter' => $delimiter, 'enclosure' => $enclosure, 'eolSequence' => $eolSequence];
        foreach ($tokens as $name => $t) {
            if ($t === '') {
                throw new \ValueError("Format $name must not be empty");
            }
        }
        // No token may contain another: that is what makes a format ambiguous.
        // (The standard terminators contain each other by design, so they are only compared against the others.)
        $eols = $this->eols();
        $pairs = [['delimiter', $delimiter, 'enclosure', $enclosure]];
        foreach ($eols as $eol) {
            $pairs[] = ['delimiter', $delimiter, 'eolSequence', $eol];
            $pairs[] = ['enclosure', $enclosure, 'eolSequence', $eol];
        }
        foreach ($pairs as [$na, $a, $nb, $b]) {
            if (str_contains($a, $b) || str_contains($b, $a)) {
                throw new \ValueError("Ambiguous format: $na and $nb overlap");
            }
        }
        // An enclosure that overlaps itself (e.g. "||") cannot be escaped by doubling unambiguously.
        for ($k = 1; $k < strlen($enclosure); $k++) {
            if (substr($enclosure, 0, $k) === substr($enclosure, -$k)) {
                throw new \ValueError('Ambiguous format: enclosure overlaps itself');
            }
        }
        if ($skipInitialSpace && ($delimiter[0] === ' ' || $enclosure[0] === ' ')) {
            throw new \ValueError('skipInitialSpace cannot be combined with a delimiter or enclosure starting with a space');
        }
    }

    /** RFC 4180: comma, double quote, CRLF, minimal quoting. The same as `new Format()`. */
    public static function rfc4180(): self
    {
        return new self();
    }

    /** RFC 4180 plus a UTF-8 BOM when encoding, which Excel needs to detect UTF-8. For ';' locales: ->with(delimiter: ';'). */
    public static function excel(): self
    {
        return new self(bom: true);
    }

    /** Tab separated, LF, no quoting at all (IANA text/tab-separated-values): encoding throws on tabs/newlines in data. */
    public static function tsv(): self
    {
        return new self("\t", '"', "\n", Quoting::Never);
    }

    /** Like RFC 4180 but with LF line endings. (Python's "unix" dialect quotes everything; this does not.) */
    public static function unix(): self
    {
        return new self(eolSequence: "\n");
    }

    /** A copy with the given constructor arguments replaced. */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @internal True if the EOL sequence is one of "\r\n", "\n", "\r" (all three are then accepted when decoding). */
    public function universalEol(): bool
    {
        return $this->eolSequence === "\r\n" || $this->eolSequence === "\n" || $this->eolSequence === "\r";
    }

    /**
     * @internal
     * @return list<string> the EOL sequences recognised when decoding
     */
    public function eols(): array
    {
        return $this->universalEol() ? ["\r\n", "\n", "\r"] : [$this->eolSequence];
    }
}
