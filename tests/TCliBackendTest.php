<?php

/**
 * TCliBackendTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use Prado\Exceptions\TIOException;
use Prado\IO\Compression\TBrotliFallbackCompressor;
use Prado\IO\Compression\TBzip2FallbackCompressor;
use Prado\IO\Compression\TGzipFallbackCompressor;
use Prado\IO\Compression\TXzFallbackCompressor;
use Prado\IO\Compression\TZstdFallbackCompressor;

/**
 * TCliBackendTest class.
 *
 * Exercises the command backend of every codec directly, rather than through
 * `compress()`/`decompress()`, which prefer the native extension whenever it is loaded.  On a
 * machine with `zlib` and `bz2` the gzip and bzip2 fallbacks would otherwise never run, and
 * the path that exists precisely for machines without those extensions would ship untested.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TCliBackendTest extends CompressorTestCase
{
	/**
	 * @return array<string, array{0: class-string, 1: string, 2: int, 3: int, 4: string[], 5: string[], 6: string[], 7: string[]}>
	 */
	public static function codecProvider(): array
	{
		return [
			//           codec                              command   min max  base compress argv          decompress argv      max flags     out-of-range flags
			'gzip' => [TGzipFallbackCompressor::class, 'gzip', 1, 9, ['-c', '-n'], ['-d', '-c'], ['-9'], []],
			'bzip2' => [TBzip2FallbackCompressor::class, 'bzip2', 1, 9, ['-z', '-c'], ['-d', '-c'], ['-9'], ['-4']],
			'zstd' => [TZstdFallbackCompressor::class, 'zstd', 1, 22, ['-c', '-q'], ['-d', '-c', '-q'], ['--ultra', '-22'], []],
			'brotli' => [TBrotliFallbackCompressor::class, 'brotli', 0, 11, ['-c'], ['-d', '-c'], ['-q', '11'], []],
			'xz' => [TXzFallbackCompressor::class, 'xz', 0, 9, ['-z', '-c', '-q', '-T', '1', '--format=xz'], ['-d', '-c', '-q'], ['-9'], []],
		];
	}

	/**
	 * @dataProvider codecProvider
	 * @param class-string $codec
	 * @param string $command
	 */
	public function testTheCommandIsNamedAndResolvesWhenInstalled(string $codec, string $command): void
	{
		self::assertSame([$command], static::invoke($codec, 'commands'), 'The codec names its command.');
		self::assertSame(
			$this->onPath($command),
			(bool) static::invoke($codec, 'cliAvailable'),
			'The command backend is available exactly when the command is on the PATH.'
		);
	}

	/**
	 * The argument vector for a level inside the command's accepted range carries that level.
	 * A level outside it, including the -1 that asks for the default, is either left to the
	 * command or pinned to the codec's own default, depending on whether the two agree: bzip2
	 * is pinned because the command's default differs from the extension's, the rest are left
	 * alone because they do not.  Either way an out-of-range number never reaches the command
	 * as an invalid flag.
	 * @dataProvider codecProvider
	 * @param class-string $codec
	 * @param string $command
	 * @param int $min
	 * @param int $max
	 * @param string[] $baseCompress
	 * @param string[] $decompress
	 * @param string[] $maxFlags
	 * @param string[] $defaultFlags
	 */
	public function testArgumentVectorsCarryOnlyAnInRangeLevel(string $codec, string $command, int $min, int $max, array $baseCompress, array $decompress, array $maxFlags, array $defaultFlags): void
	{
		self::assertSame($decompress, static::invoke($codec, 'decompressArgs'), 'The decompress vector is fixed.');
		self::assertSame([...$baseCompress, ...$maxFlags], static::invoke($codec, 'compressArgs', $max), 'The maximum level is passed through.');

		foreach ([-1, $min - 1, $max + 1] as $level) {
			self::assertSame([...$baseCompress, ...$defaultFlags], static::invoke($codec, 'compressArgs', $level), "Level {$level} is out of range and resolves to the codec default.");
		}
	}

	/**
	 * @dataProvider codecProvider
	 * @param class-string $codec
	 * @param string $command
	 */
	public function testTheCommandBackendRoundTrips(string $codec, string $command): void
	{
		$this->skipWithoutCommand($command);
		foreach (['', 'A', 'PRADO', str_repeat('abcABC123 ', 5000), random_bytes(4096)] as $data) {
			$packed = static::invoke($codec, 'cliCompress', $data, -1);
			self::assertSame($data, static::invoke($codec, 'cliDecompress', $packed), 'Command round-trip of ' . strlen($data) . ' bytes.');
		}
	}

	/**
	 * The size comparison runs over wholly compressible, deterministic data.  Comparing levels
	 * over a payload that is mostly incompressible is not a stable assertion: the framing a
	 * higher level adds can exceed what it saves, so the ordering flips on some inputs.  Random
	 * bytes are still exercised here, but only through a round-trip, which cannot flake.
	 * @dataProvider codecProvider
	 * @param class-string $codec
	 * @param string $command
	 * @param int $min
	 * @param int $max
	 */
	public function testTheCommandBackendHonorsEveryLevelInRange(string $codec, string $command, int $min, int $max): void
	{
		$this->skipWithoutCommand($command);
		$data = str_repeat('the quick brown fox ', 2000);

		$low = static::invoke($codec, 'cliCompress', $data, $min);
		$high = static::invoke($codec, 'cliCompress', $data, $max);
		self::assertSame($data, static::invoke($codec, 'cliDecompress', $low), 'The minimum level round-trips.');
		self::assertSame($data, static::invoke($codec, 'cliDecompress', $high), 'The maximum level round-trips.');
		self::assertLessThanOrEqual(strlen($low), strlen($high), 'The maximum level is no larger than the minimum.');
		self::assertLessThan(strlen($data), strlen($high), 'Repetitive data compresses.');

		$binary = random_bytes(4096);
		self::assertSame($binary, static::invoke($codec, 'cliDecompress', static::invoke($codec, 'cliCompress', $binary, $max)), 'The maximum level round-trips incompressible data.');
	}

	/**
	 * The whole point of the fallback is that a machine without the extension produces bytes
	 * the rest of the world reads, so the command output is checked against the standalone
	 * tool in both directions.
	 * @dataProvider codecProvider
	 * @param class-string $codec
	 * @param string $command
	 * @param int $min
	 * @param int $max
	 * @param string[] $baseCompress
	 * @param string[] $decompress
	 */
	public function testTheCommandBackendInteroperatesWithTheStandaloneTool(string $codec, string $command, int $min, int $max, array $baseCompress, array $decompress): void
	{
		$this->skipWithoutCommand($command);
		$data = str_repeat('interop ', 1000) . random_bytes(300);

		$ours = static::invoke($codec, 'cliCompress', $data, $min);
		self::assertSame($data, $this->rawCli([$command, ...$decompress], $ours), 'The standalone tool decodes the backend output.');

		$theirs = $this->rawCli([$command, ...$baseCompress], $data);
		self::assertSame($data, static::invoke($codec, 'cliDecompress', $theirs), 'The backend decodes the standalone tool output.');
	}

	/**
	 * @dataProvider codecProvider
	 * @param class-string $codec
	 * @param string $command
	 */
	public function testTheCommandBackendReportsCorruptDataAsAnIOException(string $codec, string $command): void
	{
		$this->skipWithoutCommand($command);
		$this->expectException(TIOException::class);
		static::invoke($codec, 'cliDecompress', 'this is definitely not a compressed stream');
	}

	private function skipWithoutCommand(string $command): void
	{
		if (!$this->onPath($command)) {
			self::markTestSkipped("The {$command} command is not on the PATH.");
		}
	}
}
