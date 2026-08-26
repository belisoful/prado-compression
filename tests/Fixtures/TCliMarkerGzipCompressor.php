<?php

/**
 * TCliMarkerGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TGzipFallbackCompressor;

/**
 * TCliMarkerGzipCompressor class.
 *
 * A test double whose command backend is always available and returns {@see CLI_MARKER}
 * instead of running a command, so a test observes which backend a mode routed a call to
 * without depending on a command being installed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TCliMarkerGzipCompressor extends TGzipFallbackCompressor
{
	/** The bytes the marker command backend returns; never valid gzip output. */
	public const CLI_MARKER = "\x01CLI\x01";

	/**
	 * The command backend is always available.
	 * @return bool Always true.
	 */
	protected static function cliAvailable(): bool
	{
		return true;
	}

	/**
	 * Returns the marker instead of compressing.
	 * @param string $data The raw bytes.
	 * @param int $level The compression level.
	 * @return string The marker.
	 */
	protected static function cliCompress(string $data, int $level): string
	{
		return self::CLI_MARKER;
	}

	/**
	 * Returns the marker instead of decompressing.
	 * @param string $data The compressed bytes.
	 * @return string The marker.
	 */
	protected static function cliDecompress(string $data): string
	{
		return self::CLI_MARKER;
	}
}
