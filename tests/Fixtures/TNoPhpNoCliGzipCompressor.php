<?php

/**
 * TNoPhpNoCliGzipCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

/**
 * TNoPhpNoCliGzipCompressor class.
 *
 * A test double with no backend at all: the command of {@see TNoCliGzipCompressor} does
 * not exist and the native PHP backend reports unavailable.  Both backends keep their real
 * failure paths, so which backend a mode routed a call to is observable in the error code
 * of the exception: the native path throws `builtincompressor_extension_required` and the
 * command path throws `clicompressor_command_missing`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TNoPhpNoCliGzipCompressor extends TNoCliGzipCompressor
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
