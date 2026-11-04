<?php

/**
 * TFunctionlessXzCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TXzFallbackCompressor;

/**
 * TFunctionlessXzCompressor class.
 *
 * A test double for an `xz` extension that is loaded but lacks the native functions: the
 * extension it requires is `zlib`, which is loaded, and its function names do not exist.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TFunctionlessXzCompressor extends TXzFallbackCompressor
{
	protected const EXTENSION = 'zlib';

	protected const ENCODE_FUNCTION = 'prado_compression_missing_xzencode';

	protected const DECODE_FUNCTION = 'prado_compression_missing_xzdecode';
}
