<?php

/**
 * TZstdFallbackCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TZstdFallbackCompressor class.
 *
 * A zstd codec that prefers the native {@see TZstdCompressor} (the `zstd` extension) and falls
 * back to the `zstd` system command when the extension is absent.  The zstd frame format is the
 * same either way, so bytes compressed by one backend decompress with the other.
 *
 * {@see isAvailable()} is true when the extension or the command is present;
 * {@see compress()}/{@see decompress()} use the native codec when the extension is loaded and
 * the command otherwise.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TZstdFallbackCompressor extends TZstdCompressor
{
	use TCliCompressorTrait;

	/** The highest level the `zstd` command accepts without `--ultra`. */
	public const MAX_CLI_LEVEL = 19;

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
	 * @param int $level The compression level 1..22, or -1 for the default.
	 * @throws \Prado\Exceptions\TIOException When no backend can compress the data.
	 * @return string The zstd bytes.
	 */
	public static function compress(string $data, int $level = -1): string
	{
		return parent::isAvailable() ? parent::compress($data, $level) : static::cliCompress($data, $level);
	}

	/**
	 * Decompresses with the native codec when present, otherwise the command.
	 * @param string $data The zstd bytes.
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
		return ['zstd'];
	}

	/**
	 * The arguments that compress standard input to standard output.  The command tops out at
	 * {@see MAX_CLI_LEVEL} without `--ultra`, so a higher level is the command default.
	 * @param int $level The compression level 1..19, or -1 for the zstd default.
	 * @return string[] The argument vector.
	 */
	protected static function compressArgs(int $level): array
	{
		$args = ['-c', '-q'];
		if ($level >= self::MIN_LEVEL && $level <= self::MAX_CLI_LEVEL) {
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
