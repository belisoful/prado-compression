# Changelog

All notable changes to `belisoful/prado-compression` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-19

Initial release.

### Added

- `TXzFallbackCompressor` — a working xz/LZMA (`.xz`) codec, backed by the `xz` command. Prado
  core ships only an inert `TXzCompressor` stub that throws `TNotSupportedException`, because
  PHP has no xz extension; installing this package provides the working codec. The native hooks
  are in place for the day an xz extension is published.
- `TGzipFallbackCompressor` — gzip (`.gz`), preferring the `zlib` extension and falling back to
  the `gzip` command.
- `TBzip2FallbackCompressor` — bzip2 (`.bz2`), preferring the `bz2` extension and falling back
  to the `bzip2` command. The block size is passed to the command explicitly, so both backends
  resolve a level identically and produce byte-identical output, including for the default.
- `TZstdFallbackCompressor` — zstd (`.zst`), preferring the `zstd` extension and falling back to
  the `zstd` command.
- `TBrotliFallbackCompressor` — brotli (`.br`), preferring the `brotli` extension and falling
  back to the `brotli` command.

Every codec implements `Prado\IO\Compression\ICompressor` and reports `isAvailable()` as true
when *either* backend is present. The compressed bytes are the standard stream of the format
whichever backend produced them, so data compressed on a machine with the extension decompresses
on a machine with only the command, and the reverse.

- `config/classMap.json` — a Prado3-style short-name to fully-qualified-name map for the five
  codecs, ready for `Prado::registerClassMap()` (added in Prado 4.4).
- `config/errorMessages.txt` — a registerable message file for `TException::addMessageFile()`.
  It defines no messages yet: the codecs raise `TIOException` only from the core base class and
  trait, so every key they surface is already defined by the framework.

### Notes

- Requires PHP 8.1+ and `pradosoft/prado` 4.4, where the `Prado\IO\Compression` codecs and
  `TCliCompressorTrait` were introduced. Until 4.4 is tagged, the `^4.4 || dev-master`
  constraint installs `dev-master`.
- The two backends of a codec always produce a valid, interoperable stream, but they do not
  always agree on how hard to compress, because a command does not expose every level its
  library does. Known divergences, documented in the README:
  - `TGzipFallbackCompressor` at level `0` stores uncompressed natively, but the `gzip` command
    has no `-0` and compresses at its default level 6.
  - `TZstdFallbackCompressor` above level 19 needs `--ultra` on the command, so the command
    backend uses zstd's default level 3 instead.

[1.0.0]: https://github.com/belisoful/prado-compression/releases/tag/v1.0.0
