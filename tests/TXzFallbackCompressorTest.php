<?php

/**
 * TXzFallbackCompressorTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use Prado\Exceptions\TIOException;
use Prado\IO\Compression\ICompressor;
use Prado\IO\Compression\TBuiltinCompressor;
use Prado\IO\Compression\TXzFallbackCompressor;

class TXzFallbackCompressorTest extends CompressorTestCase
{
	private function skipWithoutBackend(): void
	{
		if (!TXzFallbackCompressor::isAvailable()) {
			self::markTestSkipped('Neither a PHP xz extension nor the xz command is available.');
		}
	}

	public function testIsABuiltinCompressorWithCliFallback()
	{
		self::assertContains(ICompressor::class, class_implements(TXzFallbackCompressor::class));
		self::assertInstanceOf(TBuiltinCompressor::class, (new \ReflectionClass(TXzFallbackCompressor::class))->newInstanceWithoutConstructor());
		self::assertSame('xz', TXzFallbackCompressor::NAME);
		self::assertSame(0, TXzFallbackCompressor::MIN_LEVEL);
		self::assertSame(9, TXzFallbackCompressor::MAX_LEVEL);
	}

	public function testAvailabilityTracksAnyBackend()
	{
		self::assertSame(
			extension_loaded('xz') || $this->onPath('xz'),
			TXzFallbackCompressor::isAvailable(),
			'Available when the native extension or the command is present.'
		);
	}

	public function testRoundTrip()
	{
		$this->skipWithoutBackend();
		foreach (['', 'A', 'PRADO framework', str_repeat('abcABC123 ', 5000), random_bytes(4096)] as $data) {
			$packed = TXzFallbackCompressor::compress($data);
			self::assertSame($data, TXzFallbackCompressor::decompress($packed), 'xz round-trip of ' . strlen($data) . ' bytes');
		}
	}

	public function testCompressesRepetitiveData()
	{
		$this->skipWithoutBackend();
		$data = str_repeat('PRADO', 4000);
		self::assertLessThan(strlen($data), strlen(TXzFallbackCompressor::compress($data)));
	}

	/**
	 * The size comparison runs over wholly compressible, deterministic data.  With an
	 * incompressible tail the ordering is not guaranteed — preset 9 came out larger than preset
	 * 0 on about one input in twenty — so that shape is a flaky assertion, not a real property.
	 */
	public function testLevelIsHonored()
	{
		$this->skipWithoutBackend();
		$data = str_repeat('the quick brown fox ', 3000);
		$low = TXzFallbackCompressor::compress($data, 0);
		$high = TXzFallbackCompressor::compress($data, 9);
		self::assertSame($data, TXzFallbackCompressor::decompress($low), 'Preset 0 round-trips.');
		self::assertSame($data, TXzFallbackCompressor::decompress($high), 'Preset 9 round-trips.');
		self::assertLessThanOrEqual(strlen($low), strlen($high), 'The maximum preset is no larger than the minimum.');

		$binary = random_bytes(4096);
		self::assertSame($binary, TXzFallbackCompressor::decompress(TXzFallbackCompressor::compress($binary, 9)), 'Preset 9 round-trips incompressible data.');
	}

	public function testOutputIsAStandardXzStream()
	{
		$this->skipWithoutBackend();
		$packed = TXzFallbackCompressor::compress('xz magic check');
		self::assertSame("\xFD7zXZ\x00", substr($packed, 0, 6), 'An xz stream starts with the FD 37 7A 58 5A 00 magic.');
	}

	public function testCorruptDataThrows()
	{
		$this->skipWithoutBackend();
		$this->expectException(TIOException::class);
		TXzFallbackCompressor::decompress('this is definitely not an xz stream');
	}

	/**
	 * No PHP xz extension is published, so the native hooks are placeholders that report
	 * failure.  They are unreachable through `compress()`/`decompress()` while `extension_loaded('xz')`
	 * is false, so they are called here directly to pin the contract they must keep: a
	 * non-string return, which `TBuiltinCompressor` turns into a `TIOException` rather than
	 * letting a wrong value through.  When an extension is adopted, these assertions are what
	 * changes.
	 */
	public function testTheNativeBackendIsAPlaceholderThatReportsFailure()
	{
		self::assertFalse(extension_loaded('xz'), 'No PHP xz extension is published; the command backend is what runs.');
		self::assertIsNotString(static::invoke(TXzFallbackCompressor::class, 'encode', 'data', 6), 'The native compress hook reports failure.');
		self::assertIsNotString(static::invoke(TXzFallbackCompressor::class, 'decode', 'data'), 'The native decompress hook reports failure.');
	}
}
