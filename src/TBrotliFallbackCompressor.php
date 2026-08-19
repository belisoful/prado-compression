<?php

/**
 * TBrotliFallbackCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TBrotliFallbackCompressor class.
 *
 * A brotli codec that prefers the native {@see TBrotliCompressor} (the `brotli` extension) and
 * falls back to the `brotli` system command when the extension is absent.  The brotli stream is
 * the same either way, so bytes compressed by one backend decompress with the other.
 *
 * {@see isAvailable()} is true when the extension or the command is present;
 * {@see compress()}/{@see decompress()} use the native codec when the extension is loaded and
 * the command otherwise.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TBrotliFallbackCompressor extends TBrotliCompressor
{
	use TCliCompressorTrait;

	/**
	 * Returns whether the native extension or the command is available.
	 * @return bool Whether the codec can run.
	 */
	public static function isAvailable(): bool
	{
		return parent::isAvailable() || static::cliAvailable();
	}

	/**
	 * Compresses with the native codec when present, otherwise the command.
	 * @param string $data The raw bytes.
	 * @param int $level The quality 0..11, or -1 for the default.
	 * @throws \Prado\Exceptions\TIOException When no backend can compress the data.
	 * @return string The brotli bytes.
	 */
	public static function compress(string $data, int $level = -1): string
	{
		return parent::isAvailable() ? parent::compress($data, $level) : static::cliCompress($data, $level);
	}

	/**
	 * Decompresses with the native codec when present, otherwise the command.
	 * @param string $data The brotli bytes.
	 * @throws \Prado\Exceptions\TIOException When no backend can decompress the data.
	 * @return string The raw bytes.
	 */
	public static function decompress(string $data): string
	{
		return parent::isAvailable() ? parent::decompress($data) : static::cliDecompress($data);
	}

	/**
	 * The command that backs the fallback path.
	 * @return string[] The candidate command names.
	 */
	protected static function commands(): array
	{
		return ['brotli'];
	}

	/**
	 * The arguments that compress standard input to standard output.
	 * @param int $level The quality 0..11, or -1 for the brotli default.
	 * @return string[] The argument vector.
	 */
	protected static function compressArgs(int $level): array
	{
		$args = ['-c'];
		if ($level >= self::MIN_QUALITY && $level <= self::MAX_QUALITY) {
			$args[] = '-q';
			$args[] = (string) $level;
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
