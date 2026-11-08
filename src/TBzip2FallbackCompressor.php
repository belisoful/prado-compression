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
 * the command.  {@see isAvailable()} is true when a permitted backend is present, and
 * {@see TFallbackCompressorTrait::setTempDirectory() setTempDirectory()} redirects the
 * command backend's staging files to a private directory.
 *
 * Both backends resolve a level the same way, so the same request produces the same block size
 * whichever one runs; bzip2 is deterministic, so the compressed bytes are in fact identical.
 * This matters for the default: the `bzip2` command defaults to `-9`, while the extension
 * defaults to {@see TBzip2Compressor::DEFAULT_BLOCK_SIZE}, so the command is given the block
 * size explicitly rather than left to choose its own.
 *
 * Concatenated streams, as written by `pbzip2` and `lbzip2`, decode in full on both backends:
 * `bzdecompress()` stops at the end of the first stream, so {@see decode()} reads input that
 * holds a further stream through the `bzip2.decompress` stream filter.
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

	/** The start of a stream: its header, then a block or the end-of-stream magic. */
	private const STREAM_START_PATTERN = '/BZh[1-9](?:\x31\x41\x59\x26\x53\x59|\x17\x72\x45\x38\x50\x90)/';

	/** The error number of bzip2 for corrupt data. */
	private const BZ_DATA_ERROR = -4;

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
	 * The native decompression backend.  Input that holds a stream start after the first
	 * stream's header decodes through the filter; a stream start that is only a coincidence
	 * in the compressed bytes decodes to the same result.  Corruption in any stream is an
	 * error, and so are bytes that are not a stream after concatenated streams, which the
	 * filter cannot tell from corruption; after a single stream such bytes are ignored.
	 * @param string $data The bzip2 bytes.
	 * @return int|string The raw bytes, or a bzip2 error number on failure.
	 */
	protected static function decode(string $data): string|int
	{
		$first = parent::decode($data);
		if (!is_string($first) || !preg_match(self::STREAM_START_PATTERN, $data, offset: 4)) {
			return $first;
		}
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $data);
		rewind($stream);
		stream_filter_append($stream, 'bzip2.decompress', STREAM_FILTER_READ, ['concatenated' => true]);
		$failed = false;
		set_error_handler(function () use (&$failed) {
			$failed = true;
			return true;
		});
		try {
			$result = stream_get_contents($stream);
		} finally {
			restore_error_handler();
			fclose($stream);
		}
		return ($failed || !is_string($result)) ? self::BZ_DATA_ERROR : $result;
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
