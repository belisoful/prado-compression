<?php

/**
 * TXzFallbackCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TXzFallbackCompressor class.
 *
 * A working xz/LZMA {@see ICompressor} codec that prefers a native PHP xz extension and falls
 * back to the `xz` system command when the extension is absent.  It replaces the inert core
 * {@see TXzCompressor} stub for callers that install `belisoful/prado-compression`.
 *
 * The codec extends {@see TBuiltinCompressor} for the native path and mixes in
 * {@see TFallbackCompressorTrait} for the command path and the backend selection.
 * {@see TFallbackCompressorTrait::setBackendMode() setBackendMode()} selects the backends,
 * per {@see TFallbackCompressorMode}; the default prefers the extension and falls back to
 * the command, {@see isAvailable()} is true when a permitted backend is present, and
 * {@see TFallbackCompressorTrait::setTempDirectory() setTempDirectory()} redirects the
 * command backend's staging files to a private directory.  No PHP xz extension is published
 * yet, so today the command path is what runs; {@see encode()} and {@see decode()} are ready
 * for the day an extension is adopted.
 *
 * ```php
 * if (TXzFallbackCompressor::isAvailable()) {
 *     $packed = TXzFallbackCompressor::compress($data);
 *     $data = TXzFallbackCompressor::decompress($packed);
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TXzFallbackCompressor extends TBuiltinCompressor
{
	use TFallbackCompressorTrait;

	/** The wire-format name. */
	public const NAME = 'xz';

	/** The native PHP extension, preferred when present. */
	protected const EXTENSION = 'xz';

	/** The lowest xz preset level. */
	public const MIN_LEVEL = 0;

	/** The highest xz preset level. */
	public const MAX_LEVEL = 9;

	/**
	 * Compresses with the backend the {@see getBackendMode() BackendMode} selects.
	 * @param string $data The raw bytes.
	 * @param int $level The xz preset 0..9, or -1 for the default.
	 * @throws \Prado\Exceptions\TIOException When the selected backend cannot compress the data.
	 * @return string The compressed bytes.
	 */
	public static function compress(string $data, int $level = -1): string
	{
		return static::usePhpBackend() ? parent::compress($data, $level) : static::cliCompress($data, $level);
	}

	/**
	 * Decompresses with the backend the {@see getBackendMode() BackendMode} selects.
	 * @param string $data The compressed bytes.
	 * @throws \Prado\Exceptions\TIOException When the selected backend cannot decompress the data.
	 * @return string The decompressed bytes.
	 */
	public static function decompress(string $data): string
	{
		return static::usePhpBackend() ? parent::decompress($data) : static::cliDecompress($data);
	}

	/**
	 * The native compression backend, reached only when a PHP xz extension is loaded.
	 * @param string $data The raw bytes.
	 * @param int $level The xz preset 0..9, or -1 for the default.
	 * @return false|int|string The compressed bytes, or a non-string on failure.
	 */
	protected static function encode(string $data, int $level): string|int|false
	{
		// A published PHP xz extension would compress here, e.g. `return @xz_compress($data, $level);`.
		return false;
	}

	/**
	 * The native decompression backend, reached only when a PHP xz extension is loaded.
	 * @param string $data The compressed bytes.
	 * @return false|int|string The decompressed bytes, or a non-string on failure.
	 */
	protected static function decode(string $data): string|int|false
	{
		// A published PHP xz extension would decompress here, e.g. `return @xz_uncompress($data);`.
		return false;
	}

	/**
	 * The command that backs the fallback path.
	 * @return string[] The candidate command names.
	 */
	protected static function commands(): array
	{
		return ['xz'];
	}

	/**
	 * The arguments that compress standard input to standard output.
	 * @param int $level The xz preset 0..9, or -1 for the xz default.
	 * @return string[] The argument vector.
	 */
	protected static function compressArgs(int $level): array
	{
		$args = ['-z', '-c', '-q', '-T', '1'];
		if ($level >= self::MIN_LEVEL && $level <= self::MAX_LEVEL) {
			$args[] = '-' . $level;
		}
		return $args;
	}

	/**
	 * The arguments that decompress standard input to standard output.
	 * @return string[] The argument vector.
	 */
	protected static function decompressArgs(): array
	{
		return ['-d', '-c', '-q'];
	}
}
