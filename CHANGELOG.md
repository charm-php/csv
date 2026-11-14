# Changelog

## [0.1.1]
### Changed
- `ParseException` now extends `\UnexpectedValueException` instead of `\RuntimeException`, so
  `catch (\UnexpectedValueException)` catches a data error from any charm package. It is still a
  `\RuntimeException` (that is `\UnexpectedValueException`'s parent); the class name and public properties are unchanged.
- Docblocks and the README state the exception policy (new "Errors" section). No argument check changed class.

## [0.1.0]
Initial release.
