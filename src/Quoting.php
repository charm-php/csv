<?php

declare(strict_types=1);

namespace FrodeBorli\Csv;

/**
 * When the writer encloses a field.
 *
 * On reading, NONE also means "the enclosure is not special": quotes are literal text.
 */
enum Quoting
{
    /** Only fields that need it (contain a delimiter, enclosure or line break, ...). */
    case MINIMAL;
    /** Every field. */
    case ALL;
    /** Every field except PHP ints and floats. Numeric strings are still quoted. */
    case NONNUMERIC;
    /** Never. The writer throws if a field would need quoting. */
    case NONE;
}
