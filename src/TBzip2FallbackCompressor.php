<?php

/**
 * TBzip2FallbackCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TBzip2FallbackCompressor class.
 *
 * A bzip2 codec with two backends: the native {@see TBzip2Compressor} (the `bz2` extension)
 * and the `bzip2` system command.  The bzip2 format is the same either way, so bytes
 * compressed by one backend decompress with the other.
 *
 * {@see TFallbackCompressorTrait::setBackendMode() setBackendMode()} selects the backends,
 * per {@see TFallbackCompressorMode}; the default prefers the extension and falls back to
 * the command.  {@see isAvailable()} is true when a permitted backend is present.
 *
 * Both backends resolve a level the same way, so the same request produces the same block size
 * whichever one runs; bzip2 is deterministic, so the compressed bytes are in fact identical.
 * This matters for the default: the `bzip2` command defaults to `-9`, while the extension
 * defaults to {@see TBzip2Compressor::DEFAULT_BLOCK_SIZE}, so the command is given the block
 * size explicitly rather than left to choose its own.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TBzip2FallbackCompressor extends TBzip2Compressor
{
	use TFallbackCompressorTrait;

	/** The lowest bzip2 block size. */
	public const MIN_BLOCK_SIZE = 1;

	/** The highest bzip2 block size. */
	public const MAX_BLOCK_SIZE = 9;

	/**
	 * Compresses with the backend the {@see getBackendMode() BackendMode} selects.
	 * @param string $data The raw bytes.
	 * @param int $level The bzip2 block size 1..9, or -1 for {@see TBzip2Compressor::DEFAULT_BLOCK_SIZE}.
	 * @throws \Prado\Exceptions\TIOException When the selected backend cannot compress the data.
	 * @return string The bzip2 bytes.
	 */
	public static function compress(string $data, int $level = -1): string
	{
		return static::usePhpBackend() ? parent::compress($data, $level) : static::cliCompress($data, $level);
	}

	/**
	 * Decompresses with the backend the {@see getBackendMode() BackendMode} selects.
	 * @param string $data The bzip2 bytes.
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
		return ['bzip2'];
	}

	/**
	 * The arguments that compress standard input to standard output.  The block size is always
	 * passed, because a level the command is not given is a level the two backends disagree on:
	 * the command would default to `-9` where {@see TBzip2Compressor::encode()} defaults to
	 * {@see TBzip2Compressor::DEFAULT_BLOCK_SIZE}.  An out-of-range level resolves the same way
	 * the native codec resolves it, so `-1` means the same thing on both paths.
	 * @param int $level The bzip2 block size 1..9, or -1 for {@see TBzip2Compressor::DEFAULT_BLOCK_SIZE}.
	 * @return string[] The argument vector.
	 */
	protected static function compressArgs(int $level): array
	{
		$blockSize = ($level < self::MIN_BLOCK_SIZE || $level > self::MAX_BLOCK_SIZE) ? self::DEFAULT_BLOCK_SIZE : $level;
		return ['-z', '-c', '-' . $blockSize];
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
