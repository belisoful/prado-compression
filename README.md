# prado-compression

Additional [Prado](https://github.com/pradosoft/prado) `ICompressor` codecs, each backed by a
system command when its PHP extension is absent.

Prado core ships the native-extension compression codecs (gzip, zlib, deflate, bzip2, zstd,
brotli) and the negotiation façade, but a core codec is unavailable when its extension is not
installed, and xz/LZMA has no PHP extension at all. This package adds a fallback layer: each
codec here prefers the native extension and drops to the standalone command through Prado's
`TCliCompressorTrait` when the extension is missing, so the format keeps working on a machine
where the extension was never built.

## Codecs

| Class | Format | Native backend | Command backend |
|-------|--------|----------------|-----------------|
| `TXzFallbackCompressor` | xz/LZMA (`.xz`) | *none published yet* | `xz` |
| `TGzipFallbackCompressor` | gzip (`.gz`) | `zlib` extension | `gzip` |
| `TBzip2FallbackCompressor` | bzip2 (`.bz2`) | `bz2` extension | `bzip2` |
| `TZstdFallbackCompressor` | zstd (`.zst`) | `zstd` extension | `zstd` |
| `TBrotliFallbackCompressor` | brotli (`.br`) | `brotli` extension | `brotli` |

Each implements `Prado\IO\Compression\ICompressor`, so it drops into any code that accepts a
codec class:

```php
use Prado\IO\Compression\TXzFallbackCompressor;

if (TXzFallbackCompressor::isAvailable()) {
    $packed = TXzFallbackCompressor::compress($data);
    $data   = TXzFallbackCompressor::decompress($packed);
}
```

`isAvailable()` is true when *either* backend is present. The compressed bytes are the standard
stream of the format either way, so data compressed on a machine with the extension decompresses
on a machine with only the command, and the reverse.

Prado core keeps an inert `Prado\IO\Compression\TXzCompressor` stub that throws
`TNotSupportedException`; installing this package provides the working codec.

### Level fidelity across backends

The two backends always produce a valid, interoperable stream, but they do not always agree on
how hard to compress, because a command does not expose every level its library does:

- **`TGzipFallbackCompressor` at level `0`.** The `zlib` extension stores the data uncompressed
  inside a gzip wrapper; the `gzip` command has no `-0`, so the command backend falls through to
  gzip's default level 6 and compresses. Do not use level `0` to mean "do not compress" unless
  the extension is known to be present.
- **`TZstdFallbackCompressor` above level 19.** The `zstd` extension accepts 1..22; the command
  needs `--ultra` past `MAX_CLI_LEVEL` (19), so the command backend omits the flag and uses zstd's
  default level 3 instead.

Pass an explicit in-range level when the exact ratio matters.

`TBzip2FallbackCompressor` is not on that list: it passes the block size to the command
explicitly, resolving a level exactly as `TBzip2Compressor` does, so both backends select the
same block size for the same request. bzip2 is deterministic, so the two in fact produce
byte-identical output.

## Prado integration

The `config/` directory holds the package's declarative configuration, mirroring what the
framework keeps in `framework/classes.php` and `framework/Exceptions/messages/messages.txt`:

| File | Purpose |
|------|---------|
| `config/classMap.json` | Prado3-style short name to fully qualified name, for `Prado::registerClassMap()` |
| `config/errorMessages.txt` | Package error messages, for `TException::addMessageFile()` |

Neither is auto-discovered: the framework loads its own `classes.php` and `messages.txt`, and
scans no package paths. An application (or a plugin module) wires them once at start-up:

```php
use Prado\Exceptions\TException;
use Prado\Prado;

$config = __DIR__ . '/vendor/belisoful/prado-compression/config';
Prado::registerClassMap(json_decode(file_get_contents($config . '/classMap.json'), true));
TException::addMessageFile($config . '/errorMessages.txt');
```

`registerClassMap()` (Prado 4.4+) merges the map into the autoloader, so the codecs resolve by
short name the way core classes do — in a configuration file, a template, or
`Prado::createComponent('TXzFallbackCompressor')`. Existing entries win, so a package cannot
shadow a core class. Composer's PSR-4 autoloading already covers use by fully qualified name,
so the map is only needed for short-name resolution.

`config/errorMessages.txt` currently defines no messages. These codecs raise `TIOException`
only from the core base class and trait they build on, so every key they surface is already in
the framework's message file. The file is present, documented, and registerable for when the
package raises a message of its own — a key restated there would be dead anyway, because the
framework's file is consulted last and wins.

## Requirements

- PHP 8.1+
- `pradosoft/prado` 4.4 (the `Prado\IO\Compression` codecs landed in 4.4)

## Install

```bash
composer require belisoful/prado-compression
```

## Test

```bash
composer install
composer test
```

The suite covers both backends of every codec: the native path through `compress()`/`decompress()`
and the command path directly, so the fallback is tested even on a machine whose extension would
otherwise shadow it. A test that needs a backend that is not installed skips.

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```
