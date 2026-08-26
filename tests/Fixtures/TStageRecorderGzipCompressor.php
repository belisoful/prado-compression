<?php

/**
 * TStageRecorderGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TGzipFallbackCompressor;

/**
 * TStageRecorderGzipCompressor class.
 *
 * A test double that stages files exactly as the real codec does while recording every
 * path in {@see $staged}, so a test proves where the staging files of a run were created
 * even though the codec removes them before returning.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TStageRecorderGzipCompressor extends TGzipFallbackCompressor
{
	/** @var string[] Every staging file path created since the last {@see reset()}. */
	public static array $staged = [];

	/**
	 * Clears the recorded paths.
	 */
	public static function reset(): void
	{
		self::$staged = [];
	}

	/**
	 * Stages the file through the real backend and records the resulting path.
	 * @param string $directory The validated staging directory.
	 * @return false|string The created file path, or false on failure.
	 */
	protected static function cliStageFile(string $directory): false|string
	{
		$path = parent::cliStageFile($directory);
		if (is_string($path)) {
			self::$staged[] = $path;
		}
		return $path;
	}
}
