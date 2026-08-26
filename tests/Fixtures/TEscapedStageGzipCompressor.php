<?php

/**
 * TEscapedStageGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TGzipFallbackCompressor;

/**
 * TEscapedStageGzipCompressor class.
 *
 * A test double whose staging hook ignores the configured directory and creates the file
 * in the system temporary directory instead, simulating the silent fallback
 * {@see https://www.php.net/tempnam tempnam} performs when its directory argument is
 * unusable.  The containment check must remove the file and throw rather than hand the
 * data to the shared directory; {@see $lastStaged} lets a test verify the removal.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TEscapedStageGzipCompressor extends TGzipFallbackCompressor
{
	/** @var ?string The path of the last file the hook created. */
	public static ?string $lastStaged = null;

	/**
	 * Creates the staging file in the system temporary directory, wherever it was asked to.
	 * @param string $directory The validated staging directory, ignored.
	 * @return false|string The created file path, or false on failure.
	 */
	protected static function cliStageFile(string $directory): false|string
	{
		return self::$lastStaged = tempnam(sys_get_temp_dir(), 'prado_cli_');
	}
}
