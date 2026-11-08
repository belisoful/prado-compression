<?php

/**
 * TGzipFallbackCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TGzipFallbackCompressor class.
 *
 * A gzip codec with two backends: the native {@see TGzipCompressor} (the `zlib` extension)
 * and the `gzip` system command.  The gzip format is the same either way, so bytes compressed
 * by one backend decompress with the other.
 *
 * {@see TFallbackCompressorTrait::setBackendMode() setBackendMode()} selects the backends,
 * per {@see TFallbackCompressorMode}; the default prefers the extension and falls back to
 * the command.  {@see isAvailable()} is true when a permitted backend is present, and
 * {@see TFallbackCompressorTrait::setTempDirectory() setTempDirectory()} redirects the
 * command backend's staging files to a private directory.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TGzipFallbackCompressor extends TGzipCompressor
{
	use TFallbackCompressorTrait;

	/**
	 * Compresses with the backend the {@see getBackendMode() BackendMode} selects.
	 * @param string $data The raw bytes.
	 * @param int $level The compression level 0..9, or -1 for the default.
	 * @throws \Prado\Exceptions\TIOException When the selected backend cannot compress the data.
	 * @return string The gzip bytes.
	 */
	public static function compress(string $data, int $level = -1): string
	{
		return static::usePhpBackend() ? parent::compress($data, $level) : static::cliCompress($data, $level);
	}

	/**
	 * Decompresses with the backend the {@see getBackendMode() BackendMode} selects.
	 * @param string $data The gzip bytes.
	 * @throws \Prado\Exceptions\TIOException When the selected backend cannot decompress the data.
	 * @return string The raw bytes.
	 */
	public static function decompress(string $data): string
	{
		return static::usePhpBackend() ? parent::decompress($data) : static::cliDecompress($data);
	}

	/**
	 * The command that backs the fallback path.
	 * @return string[] The candidate command names.
	 */
	protected static function commands(): array
	{
		return ['gzip'];
	}

	/**
	 * The arguments that compress standard input to standard output.  `-n` keeps the staging
	 * file's name and time out of the header, so equal input gives equal bytes.
	 * @param int $level The compression level 1..9, or -1 for the gzip default.
	 * @return string[] The argument vector.
	 */
	protected static function compressArgs(int $level): array
	{
		$args = ['-c', '-n'];
		if ($level >= 1 && $level <= 9) {
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
		return ['-d', '-c'];
	}
}
