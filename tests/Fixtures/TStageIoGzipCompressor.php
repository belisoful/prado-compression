<?php

/**
 * TStageIoGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TGzipFallbackCompressor;

/**
 * TStageIoGzipCompressor class.
 *
 * A test double whose staging file reads and writes can fail, as on a full disk, so the
 * command run's handling of a short write and a failed read is observable.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TStageIoGzipCompressor extends TGzipFallbackCompressor
{
	/** @var null|false|int What a write reports instead of writing, or null to write. */
	public static null|false|int $writeResult = null;

	/** @var bool Whether a read fails. */
	public static bool $readFails = false;

	/** @var bool Whether a write removes the file, so the command cannot start on it. */
	public static bool $writeRemoves = false;

	/**
	 * Restores the real reads and writes.
	 */
	public static function reset(): void
	{
		self::$writeResult = null;
		self::$readFails = false;
		self::$writeRemoves = false;
	}

	/**
	 * @param string $path The staging file path.
	 * @param string $data The bytes to write.
	 * @return false|int The number of bytes reported written, or false on failure.
	 */
	protected static function cliWriteStage(string $path, string $data): false|int
	{
		if (self::$writeRemoves) {
			unlink($path);
			return strlen($data);
		}
		return self::$writeResult ?? parent::cliWriteStage($path, $data);
	}

	/**
	 * @param string $path The staging file path.
	 * @return false|string The bytes read, or false on failure.
	 */
	protected static function cliReadStage(string $path): false|string
	{
		return self::$readFails ? false : parent::cliReadStage($path);
	}
}
