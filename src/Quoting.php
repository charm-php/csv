<?php

declare(strict_types=1);

namespace Charm\Csv;

/**
 * When the Encoder encloses a field.
 *
 * On decoding, Never also means "the enclosure is not special": quotes are literal text.
 */
enum Quoting
{
    /** Only fields that need it (contain a delimiter, enclosure or line break, ...). */
    case Minimal;
    /** Every field. */
    case All;
    /** Every field except PHP ints and floats. Numeric strings are still quoted. */
    case NonNumeric;
    /** Never. The Encoder throws if a field would need quoting. */
    case Never;
}
