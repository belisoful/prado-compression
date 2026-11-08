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
use Prado\IO\Compression\Tests\Fixtures\TFunctionlessXzCompressor;
use Prado\IO\Compression\Tests\Fixtures\TNativeXzCompressor;
use Prado\IO\Compression\TFallbackCompressorMode;
use Prado\IO\Compression\TXzFallbackCompressor;

class TXzFallbackCompressorTest extends CompressorTestCase
{
	protected function tearDown(): void
	{
		TXzFallbackCompressor::setBackendMode(null);
		TNativeXzCompressor::$calls = [];
		parent::tearDown();
	}

	private function skipWithoutCommand(): void
	{
		if (!$this->onPath('xz')) {
			self::markTestSkipped('The xz command is not available.');
		}
	}

	/**
	 * The fixtures that simulate the `xz` extension name `zlib` as the extension they require.
	 */
	private function skipWithoutFixtureExtension(): void
	{
		if (!extension_loaded('zlib')) {
			self::markTestSkipped('The zlib extension stands in for the xz extension.');
		}
	}

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
			(extension_loaded('xz') && function_exists('xzencode') && function_exists('xzdecode')) || $this->onPath('xz'),
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
	 * Without the `xz` extension the native backend is unavailable, so the default mode runs
	 * the command.
	 */
	public function testTheNativeBackendNeedsTheExtension()
	{
		if (extension_loaded('xz')) {
			self::markTestSkipped('The xz extension is loaded.');
		}
		self::assertFalse(static::invoke(TXzFallbackCompressor::class, 'phpAvailable'), 'No extension, no native backend.');
		TXzFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
		self::assertFalse(TXzFallbackCompressor::isAvailable(), 'PhpOnly is unavailable without the extension.');
	}

	/**
	 * With the extension loaded, the default mode compresses and decompresses through its
	 * functions, and the stream is one the command reads.
	 */
	public function testTheNativeBackendServesTheDefaultMode()
	{
		$this->skipWithoutFixtureExtension();
		$this->skipWithoutCommand();
		self::assertTrue(static::invoke(TNativeXzCompressor::class, 'phpAvailable'), 'The extension and its functions make the native backend available.');

		$data = str_repeat('PRADO native xz ', 500);
		$packed = TNativeXzCompressor::compress($data);
		self::assertSame("\xFD7zXZ\x00", substr($packed, 0, 6), 'The native backend writes an xz stream.');
		self::assertSame($data, TNativeXzCompressor::decompress($packed), 'The native backend round-trips.');
		self::assertSame(['xzencode', 'xzdecode'], array_column(TNativeXzCompressor::$calls, 0), 'Both calls ran natively.');

		TXzFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);
		self::assertSame($data, TXzFallbackCompressor::decompress($packed), 'The command reads the native stream.');
	}

	/**
	 * The preset is passed only when it is in range; otherwise the extension's own default
	 * applies, so no out-of-range value reaches a native function.
	 */
	public function testTheNativeBackendPassesOnlyAnInRangePreset()
	{
		$this->skipWithoutFixtureExtension();
		$this->skipWithoutCommand();
		TNativeXzCompressor::compress('a', 0);
		TNativeXzCompressor::compress('b', 9);
		TNativeXzCompressor::compress('c');
		TNativeXzCompressor::compress('d', 10);
		self::assertSame([['a', 0], ['b', 9], ['c'], ['d']], array_column(TNativeXzCompressor::$calls, 1));
	}

	public function testTheNativeBackendReportsCorruptData()
	{
		$this->skipWithoutFixtureExtension();
		$this->skipWithoutCommand();
		$this->assertThrowsIOCode('builtincompressor_decompress_failed', fn () => TNativeXzCompressor::decompress('this is definitely not an xz stream'));
	}

	/**
	 * An `xz` extension that lacks `xzencode()` and `xzdecode()` is no native backend: the
	 * default mode falls back to the command rather than failing every call.
	 */
	public function testAnExtensionWithoutTheFunctionsFallsBackToTheCommand()
	{
		$this->skipWithoutFixtureExtension();
		self::assertFalse(static::invoke(TFunctionlessXzCompressor::class, 'phpAvailable'), 'The functions are required.');

		TXzFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
		self::assertFalse(TFunctionlessXzCompressor::isAvailable(), 'PhpOnly is unavailable without the functions.');
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TFunctionlessXzCompressor::compress('data'));

		TXzFallbackCompressor::setBackendMode(null);
		$this->skipWithoutCommand();
		self::assertSame('data', TFunctionlessXzCompressor::decompress(TFunctionlessXzCompressor::compress('data')), 'The default mode runs the command.');
	}
}
