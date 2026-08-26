<?php

/**
 * TFailingStageGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TGzipFallbackCompressor;

/**
 * TFailingStageGzipCompressor class.
 *
 * A test double whose staging hook reports failure, so a test reaches the creation-failure
 * path of the configured-directory staging without filling a disk or revoking permissions
 * mid-run.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFailingStageGzipCompressor extends TGzipFallbackCompressor
{
	/**
	 * Never creates a file.
	 * @param string $directory The validated staging directory, ignored.
	 * @return false|string Always false.
	 */
	protected static function cliStageFile(string $directory): false|string
	{
		return false;
	}
}
