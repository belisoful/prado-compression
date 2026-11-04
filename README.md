# prado-compression

Additional [Prado](https://github.com/pradosoft/prado) `ICompressor` codecs, each backed by a
system command when its PHP extension is absent.

Prado core ships the native-extension compression codecs (gzip, zlib, deflate, bzip2, zstd,
brotli) and the negotiation façade, but a core codec is unavailable when its extension is not
installed, and core has no xz/LZMA codec at all. This package adds a fallback layer: each
codec here prefers the native extension and drops to the standalone command through Prado's
`TCliCompressorTrait` when the extension is missing, so the format keeps working on a machine
where the extension was never built. The order is configurable per codec through a
[backend mode](#backend-selection), and the command backend stages data through temporary
files, which a shared system must be [set up to keep private](#securing-the-command-backend).

## Codecs

| Class | Format | Native backend | Command backend |
|-------|--------|----------------|-----------------|
| `TXzFallbackCompressor` | xz/LZMA (`.xz`) | `xz` extension ([php-ext-xz](https://github.com/codemasher/php-ext-xz)) | `xz` |
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

`isAvailable()` is true when a backend the codec's [mode](#backend-selection) permits is
present; under the default mode that is *either* backend. The compressed bytes are the standard
stream of the format either way, so data compressed on a machine with the extension decompresses
on a machine with only the command, and the reverse.

Prado core keeps an inert `Prado\IO\Compression\TXzCompressor` stub that throws
`TNotSupportedException`; installing this package provides the working codec.

## Backend selection

Each codec selects between its two backends through a static `BackendMode` property that holds
a `TFallbackCompressorMode` value:

| Mode | Selection |
|------|-----------|
| `PhpFirst` | The extension when it is loaded, otherwise the command. The default. |
| `CliFirst` | The command when it is present, otherwise the extension. |
| `PhpOnly` | The extension only; the command never runs. |
| `CliOnly` | The command only; the extension is never used. |

```php
use Prado\IO\Compression\TFallbackCompressorMode;
use Prado\IO\Compression\TGzipFallbackCompressor;

TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliFirst);
TGzipFallbackCompressor::getBackendMode();     // 'CliFirst'
TGzipFallbackCompressor::setBackendMode(null); // restores the default, PhpFirst
```

The property is per codec class: setting the gzip mode leaves the zstd mode untouched. The
setter matches its string case-insensitively and throws `TInvalidDataValueException` for a
value that is not a mode.

The mode routes by availability, and it changes what `isAvailable()` reports. An "only" mode
counts only its named backend, so a codec in `PhpOnly` mode without its extension is
unavailable even when the command is installed, and a call routed to a missing backend throws
that backend's `TIOException`. A "first" mode uses its preferred backend when that backend is
present and the other one otherwise.

The default `PhpFirst` is the selection the codecs made before the property existed, so code
written against 1.0 behaves identically.

## Securing the command backend

The command backend stages the command's standard input and output through temporary files —
by default in PHP's temporary directory (`sys_get_temp_dir()`), or in the per-codec directory
set with `setTempDirectory()` — so every byte a codec compresses or decompresses through a
command is written to disk for the life of the command run. On a single-tenant system with a
private staging directory the data stays contained; on a shared or misconfigured system it
can leak to other local users:

- `tempnam()` creates the staging files readable only by their owner (`0600`), but every
  process running as the same system user can read them, whichever directory they are in. On
  a shared host where many applications run as one user (such as `www-data`), another
  tenant's code can read the plaintext while the command runs.
- The staging directory may be backed up, snapshotted, or on a disk that retains remnants,
  so the bytes can outlive the `unlink()` that removes the files.
- The command is resolved by scanning `PATH`. A `PATH` directory writable by another user
  lets that user substitute the binary and read everything piped through it.

Set the command backend up safely:

1. **Point the codec at a private staging directory.** The static
   `setTempDirectory()` property redirects a codec's staging files to a directory owned by
   the application user, mode `0700`, excluded from backups — no `php.ini` change needed:

   ```php
   TXzFallbackCompressor::setTempDirectory('/var/lib/myapp/tmp');
   TXzFallbackCompressor::getTempDirectory();     // the canonical path
   TXzFallbackCompressor::setTempDirectory(null); // restores the system directory
   ```

   The property is per codec class, and the setting is fail-closed: the directory must
   exist and be writable when set (`TInvalidDataValueException` otherwise) and is validated
   again at every use, so a directory that later disappears or loses its permissions raises
   a `TIOException` at the call instead of silently falling back to the shared system
   directory — the fallback `tempnam()` itself performs is detected, the escaped file
   removed, and the call rejected. The two error keys this raises,
   `fallbackcompressor_tempdirectory_invalid` and `fallbackcompressor_tempdirectory_escaped`,
   are defined in `config/errorMessages.txt`.

2. **Give PHP a private temporary directory.** For codecs left on the default, point
   `sys_temp_dir` in `php.ini` (or the `TMPDIR` environment variable) at a directory owned
   by the application user, mode `0700`, excluded from backups:

   ```ini
   sys_temp_dir = /var/lib/myapp/tmp
   ```

   Under systemd, `PrivateTmp=true` on the `php-fpm` service isolates `/tmp` without a
   `php.ini` change.

3. **Run each application as its own system user.** Owner-only file modes protect nothing
   between applications that share a user.

4. **Keep `PATH` minimal and root-owned.** Every directory on the PHP process's `PATH` should
   be writable only by root (for example `PATH=/usr/bin:/bin`). To remove the lookup entirely,
   subclass the codec and return absolute paths from `commands()`:

   ```php
   class TSystemXzCompressor extends TXzFallbackCompressor
   {
       protected static function commands(): array
       {
           return ['/usr/bin/xz'];
       }
   }
   ```

5. **Keep sensitive data off the command path.** For data that must never touch disk, install
   the extension and set the codec to `PhpOnly`; the mode guarantees the command, and with it
   the temporary files, is never used:

   ```php
   TZstdFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
   ```

The native extension backend compresses in memory and writes no files.

### Level fidelity across backends

The two backends always produce a valid, interoperable stream, but they do not always agree on
how hard to compress, because a command does not expose every level its library does:

- **`TGzipFallbackCompressor` at level `0`.** The `zlib` extension stores the data uncompressed
  inside a gzip wrapper; the `gzip` command has no `-0`, so the command backend falls through to
  gzip's default level 6 and compresses. Do not use level `0` to mean "do not compress" unless
  the extension is known to be present.

Pass an explicit in-range level when the exact ratio matters.

`TBzip2FallbackCompressor` is not on that list: it passes the block size to the command
explicitly, resolving a level exactly as `TBzip2Compressor` does, so both backends select the
same block size for the same request. bzip2 is deterministic, so the two in fact produce
byte-identical output. `TZstdFallbackCompressor` is not on it either: levels 20..22 reach the
command with `--ultra`, so both backends honor every level the extension accepts.

### Input acceptance across backends

Both backends reject data that is not their format, but the native functions are more lenient
with a damaged or padded stream than the commands, which validate the whole input:

- **gzip.** `gzdecode()` ignores bytes after a complete gzip member; the `gzip` command rejects
  the trailing data and the command backend raises `clicompressor_command_failed`.
- **bzip2.** `TBzip2Compressor` (the `bz2` extension) decodes an empty string to an empty string
  and can decode a stream missing its last bytes; the `bzip2` command rejects both.

So a `PhpFirst` codec may accept input that the same codec in `CliOnly` rejects. Verify a
checksum or length of your own where an altered stream must be detected regardless of backend.

## Prado integration

The `config/` directory holds the package's declarative configuration, mirroring what the
framework keeps in `framework/classes.php` and `framework/Exceptions/messages/messages.txt`:

| File | Purpose |
|------|---------|
| `config/classMap.json` | Prado3-style short name to fully qualified name, for `Prado::registerClassMap()` |
| `config/errorMessages.txt` | Package error messages, for `TException::addMessageFile()` |

`composer.json` declares both under `extra.prado`, so an application installed through
Composer needs no wiring: Prado registers every installed extension's `error-messages` and
`class-map` system-wide before any module initializes. Outside that discovery, the files are
registered by hand once at start-up:

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

`config/errorMessages.txt` defines the two keys the package raises itself,
`fallbackcompressor_tempdirectory_invalid` and `fallbackcompressor_tempdirectory_escaped`,
from the [staging-directory validation](#securing-the-command-backend). Registering the file
makes those exceptions render as readable text; unregistered, an exception still carries its
key as the message, so behavior is unaffected. Every other key the codecs surface comes from
the core base class and trait they build on and is already in the framework's message file.
A registered file overrides the framework's, so a key restated here would replace the
framework text; the package defines only its own keys.

Each message is an ICU MessageFormat pattern, as Prado formats every file message with
`MessageFormatter`. A placeholder shown in quotes doubles its apostrophes, `''{0}''`; a single
`'{0}'` is ICU-quoted literal text and renders as `{0}`.

## Requirements

- PHP 8.2+ (CI covers 8.2 through 8.5), as Prado 4.4 requires
- `pradosoft/prado` 4.4 (the `Prado\IO\Compression` codecs landed in 4.4)

## Install

```bash
composer require belisoful/prado-compression
```

Until Prado 4.4.0 is tagged, the framework resolves from its `4.4.x-dev` branch alias, so an
application with the default `stable` minimum stability allows it explicitly:

```bash
composer require pradosoft/prado:4.4.x-dev@dev belisoful/prado-compression
```

## Test

```bash
composer install
composer test
```

The suite covers both backends of every codec: the native path through `compress()`/`decompress()`
and the command path directly, so the fallback is tested even on a machine whose extension would
otherwise shadow it. The backend modes are tested through fixtures whose command backend returns
a marker, so the routing is observable without a command installed. A test that needs a backend
that is not installed skips.

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```

Line coverage of `src/` is 100%. Branch coverage
(`php -d memory_limit=1G vendor/bin/phpunit --path-coverage --coverage-text`) is complete for
every branch a machine can reach: the native arm of a codec runs only where its extension is
loaded (the xz native arm runs through a fixture standing in for the extension), and two artifacts of the
tooling remain even then — Xdebug enumerates short-circuit paths that cannot occur, and
php-code-coverage merges the per-class copies of a trait method under one record, which can
under-report an arm the tests demonstrably execute.
