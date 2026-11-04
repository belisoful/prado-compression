<?php

/**
 * TNativeXzCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests\Fixtures;

use Prado\IO\Compression\TXzFallbackCompressor;

/**
 * TNativeXzCompressor class.
 *
 * A test double that simulates the `xz` extension: the extension it requires is `zlib`, which
 * is loaded, and its native functions stand in for `xzencode()` and `xzdecode()`.  They record
 * their arguments and produce real xz streams through the `xz` command, so the native path is
 * exercised end to end without the extension installed.  A decoding failure returns false, as
 * the extension's functions do.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TNativeXzCompressor extends TXzFallbackCompressor
{
	protected const EXTENSION = 'zlib';

	protected const ENCODE_FUNCTION = self::class . '::fakeEncode';

	protected const DECODE_FUNCTION = self::class . '::fakeDecode';

	/** @var array<int, array{string, mixed[]}> The native calls made, function name and arguments. */
	public static array $calls = [];

	/**
	 * Stands in for `xzencode()`.
	 * @param string $data The raw bytes.
	 * @param int ...$level The preset, when one is passed.
	 * @return string The xz bytes.
	 */
	public static function fakeEncode(string $data, int ...$level): string
	{
		self::$calls[] = ['xzencode', [$data, ...$level]];
		return static::cliCompress($data, $level[0] ?? -1);
	}

	/**
	 * Stands in for `xzdecode()`.
	 * @param string $data The xz bytes.
	 * @return false|string The raw bytes, or false when the data does not decode.
	 */
	public static function fakeDecode(string $data): false|string
	{
		self::$calls[] = ['xzdecode', [$data]];
		try {
			return static::cliDecompress($data);
		} catch (\Prado\Exceptions\TIOException) {
			return false;
		}
	}
}
