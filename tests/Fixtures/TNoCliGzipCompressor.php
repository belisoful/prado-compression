<?php

/**
 * TNoCliGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TGzipFallbackCompressor;

/**
 * TNoCliGzipCompressor class.
 *
 * A test double whose command backend names a command that does not exist, simulating a
 * machine without the command.  The backend resolves as unavailable through the real
 * `PATH` scan, and a call routed to it throws the real missing-command exception.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TNoCliGzipCompressor extends TGzipFallbackCompressor
{
	/**
	 * Names a command that is not installed.
	 * @return string[] The candidate command names.
	 */
	protected static function commands(): array
	{
		return ['prado-compression-test-no-such-command'];
	}
}
