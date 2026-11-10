<?php

declare(strict_types=1);

namespace Charm\Csv;

/** How the Decoder treats malformed input. */
enum Mode
{
    /**
     * Throw ParseException (with line and column) on a stray quote, text after a closing quote, an
     * unterminated enclosure, a record with a different field count than the first, or (with a header)
     * an empty or duplicate column name.
     */
    case Strict;
    /**
     * Recover the way fgetcsv() does: stray quotes are literal text, text after a closing quote is
     * appended to the field, an unterminated enclosure runs to the end of the input. With a header,
     * short records are padded with null and extra fields are kept under integer keys.
     */
    case Lax;
}
