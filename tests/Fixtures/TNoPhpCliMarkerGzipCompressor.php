<?php

/**
 * TNoPhpCliMarkerGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

/**
 * TNoPhpCliMarkerGzipCompressor class.
 *
 * A test double with the marker command backend of {@see TCliMarkerGzipCompressor} and no
 * native PHP backend, simulating a machine without the extension.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TNoPhpCliMarkerGzipCompressor extends TCliMarkerGzipCompressor
{
	/**
	 * The native PHP backend is never available.
	 * @return bool Always false.
	 */
	protected static function phpAvailable(): bool
	{
		return false;
	}
}
