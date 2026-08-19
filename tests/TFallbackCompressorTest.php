<?php

/**
 * TFallbackCompressorTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use Prado\IO\Compression\ICompressor;
use Prado\IO\Compression\TBrotliCompressor;
use Prado\IO\Compression\TBrotliFallbackCompressor;
use Prado\IO\Compression\TBzip2Compressor;
use Prado\IO\Compression\TBzip2FallbackCompressor;
use Prado\IO\Compression\TGzipCompressor;
use Prado\IO\Compression\TGzipFallbackCompressor;
use Prado\IO\Compression\TZstdCompressor;
use Prado\IO\Compression\TZstdFallbackCompressor;

class TFallbackCompressorTest extends CompressorTestCase
{
	/**
	 * @return array<string, array{0: class-string, 1: class-string, 2: string, 3: string, 4: string[], 5: string[]}>
	 */
	public static function fallbackProvider(): array
	{
		return [
			//          fallback                          native                 ext        command   raw compress argv        raw decompress argv
			'gzip' => [TGzipFallbackCompressor::class, TGzipCompressor::class, 'zlib', 'gzip', ['gzip', '-c'], ['gzip', '-dc']],
			'bzip2' => [TBzip2FallbackCompressor::class, TBzip2Compressor::class, 'bz2', 'bzip2', ['bzip2', '-zc'], ['bzip2', '-dc']],
			'zstd' => [TZstdFallbackCompressor::class, TZstdCompressor::class, 'zstd', 'zstd', ['zstd', '-cq'], ['zstd', '-dcq']],
			'brotli' => [TBrotliFallbackCompressor::class, TBrotliCompressor::class, 'brotli', 'brotli', ['brotli', '-c'], ['brotli', '-dc']],
		];
	}

	/**
	 * @dataProvider fallbackProvider
	 * @param class-string $fallback
	 * @param class-string $native
	 */
	public function testExtendsTheNativeCodecAndImplementsContract(string $fallback, string $native): void
	{
		self::assertContains(ICompressor::class, class_implements($fallback));
		self::assertInstanceOf($native, (new \ReflectionClass($fallback))->newInstanceWithoutConstructor(), 'The fallback extends its native codec.');
		self::assertSame($native::NAME, $fallback::NAME, 'The fallback keeps the wire-format name of the codec it replaces.');
	}

	/**
	 * @dataProvider fallbackProvider
	 * @param class-string $fallback
	 * @param string $native
	 * @param string $extension
	 * @param string $command
	 */
	public function testAvailableWhenEitherBackendIsPresent(string $fallback, string $native, string $extension, string $command): void
	{
		self::assertSame(
			extension_loaded($extension) || $this->onPath($command),
			$fallback::isAvailable(),
			'Available when the native extension or the command is present.'
		);
	}

	/**
	 * @dataProvider fallbackProvider
	 * @param class-string $fallback
	 */
	public function testRoundTrip(string $fallback): void
	{
		$this->skipUnless($fallback);
		foreach (['', 'A', 'PRADO', str_repeat('abcABC123 ', 5000), random_bytes(4096)] as $data) {
			$packed = $fallback::compress($data);
			self::assertSame($data, $fallback::decompress($packed), 'Round-trip of ' . strlen($data) . ' bytes.');
		}
	}

	/**
	 * @dataProvider fallbackProvider
	 * @param class-string $fallback
	 */
	public function testCompressesRepetitiveData(string $fallback): void
	{
		$this->skipUnless($fallback);
		$data = str_repeat('PRADO', 4000);
		self::assertLessThan(strlen($data), strlen($fallback::compress($data)));
	}

	/**
	 * The bytes the fallback produces are a standard stream the standalone command reads,
	 * whichever backend ran; this is the guarantee that lets one machine compress and another
	 * decompress.
	 * @dataProvider fallbackProvider
	 * @param class-string $fallback
	 * @param string[] $rawCompress
	 * @param string[] $rawDecompress
	 * @param string $native
	 * @param string $extension
	 * @param string $command
	 */
	public function testFallbackAndCommandOutputsInteroperate(string $fallback, string $native, string $extension, string $command, array $rawCompress, array $rawDecompress): void
	{
		if (!$this->onPath($command)) {
			self::markTestSkipped("The {$command} command is not on the PATH.");
		}
		$data = str_repeat('interop ', 1000) . random_bytes(300);

		$ours = $fallback::compress($data, 6);
		self::assertSame($data, $this->rawCli($rawDecompress, $ours), 'The standalone command decodes the fallback output.');

		$theirs = $this->rawCli($rawCompress, $data);
		self::assertSame($data, $fallback::decompress($theirs), 'The fallback decodes the standalone command output.');
	}

	/**
	 * Whichever backend `compress()` picks, the other one reads its bytes.  This is what makes
	 * the fallback safe to deploy across a fleet where some machines have the extension and
	 * some do not, and it is only checkable where both backends are present.
	 * @dataProvider fallbackProvider
	 * @param class-string $fallback
	 * @param string $native
	 * @param string $extension
	 * @param string $command
	 */
	public function testTheTwoBackendsReadEachOther(string $fallback, string $native, string $extension, string $command): void
	{
		if (!extension_loaded($extension) || !$this->onPath($command)) {
			self::markTestSkipped("Both the {$extension} extension and the {$command} command are needed to compare backends.");
		}
		$data = str_repeat('cross backend ', 800) . random_bytes(256);

		$nativeBytes = $native::compress($data, 6);
		$cliBytes = static::invoke($fallback, 'cliCompress', $data, 6);

		self::assertSame($data, static::invoke($fallback, 'cliDecompress', $nativeBytes), 'The command backend reads the native output.');
		self::assertSame($data, $native::decompress($cliBytes), 'The native codec reads the command output.');
	}

	/**
	 * The bzip2 backends resolve a requested level identically, so the same request selects the
	 * same block size whichever backend runs and — bzip2 being deterministic — produces the same
	 * bytes.  The default is the case worth pinning: the `bzip2` command's own default is `-9`
	 * while the extension's is block size 4, so a command backend left to choose would compress
	 * harder on a machine without the extension than on one with it, for the identical call.
	 */
	public function testBzip2BackendsAgreeByteForByteOnEveryLevel(): void
	{
		if (!extension_loaded('bz2') || !$this->onPath('bzip2')) {
			self::markTestSkipped('Both the bz2 extension and the bzip2 command are needed to compare backends.');
		}
		$data = str_repeat('block size parity ', 2000) . random_bytes(256);

		foreach ([-1, 0, 1, 4, 9, 10] as $level) {
			self::assertSame(
				TBzip2Compressor::compress($data, $level),
				static::invoke(TBzip2FallbackCompressor::class, 'cliCompress', $data, $level),
				"Both backends produce the same bytes at level {$level}."
			);
		}
	}

	/**
	 * @param class-string $fallback
	 */
	private function skipUnless(string $fallback): void
	{
		if (!$fallback::isAvailable()) {
			self::markTestSkipped('Neither the native extension nor the command is available.');
		}
	}
}
