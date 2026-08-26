<?php

/**
 * TTempDirectoryTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Compression\TBrotliFallbackCompressor;
use Prado\IO\Compression\TBzip2FallbackCompressor;
use Prado\IO\Compression\TFallbackCompressorMode;
use Prado\IO\Compression\TGzipFallbackCompressor;
use Prado\IO\Compression\TXzFallbackCompressor;
use Prado\IO\Compression\TZstdFallbackCompressor;
use Prado\IO\Compression\Tests\Fixtures\TEscapedStageGzipCompressor;
use Prado\IO\Compression\Tests\Fixtures\TFailingStageGzipCompressor;
use Prado\IO\Compression\Tests\Fixtures\TStageRecorderGzipCompressor;

/**
 * TTempDirectoryTest class.
 *
 * Exercises the `TempDirectory` property of the fallback codecs: the default, the setter's
 * validation, the per-class isolation, and the fail-closed staging it buys — a directory
 * that becomes unusable raises an exception at the call, and a staging file that lands
 * outside the directory is removed and rejected rather than trusted.  The staging is
 * observed through the fixtures in `tests/Fixtures/`, which record, misdirect, or fail the
 * file creation, so every failure path is reachable without racing the filesystem.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TTempDirectoryTest extends CompressorTestCase
{
	/** @var array<class-string> Every class with its own copy of the property. */
	private const CODECS = [
		TBrotliFallbackCompressor::class,
		TBzip2FallbackCompressor::class,
		TGzipFallbackCompressor::class,
		TXzFallbackCompressor::class,
		TZstdFallbackCompressor::class,
	];

	private string $scratch = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prado_tempdir_test_' . bin2hex(random_bytes(6));
		self::assertTrue(mkdir($this->scratch, 0o700), 'The scratch staging directory is created.');
	}

	protected function tearDown(): void
	{
		foreach (self::CODECS as $codec) {
			$codec::setTempDirectory(null);
			$codec::setBackendMode(null);
		}
		TStageRecorderGzipCompressor::reset();
		TEscapedStageGzipCompressor::$lastStaged = null;
		if (is_dir($this->scratch)) {
			@chmod($this->scratch, 0o700);
			foreach ((array) glob($this->scratch . DIRECTORY_SEPARATOR . '*') as $file) {
				@unlink((string) $file);
			}
			@rmdir($this->scratch);
		}
		parent::tearDown();
	}

	public function testTheDefaultIsTheSystemTemporaryDirectory(): void
	{
		foreach (self::CODECS as $codec) {
			self::assertNull($codec::getTempDirectory(), "{$codec} stages in the system temporary directory by default.");
		}
	}

	public function testTheSetterCanonicalizesThePathAndNullRestoresTheDefault(): void
	{
		foreach (self::CODECS as $codec) {
			$codec::setTempDirectory($this->scratch . DIRECTORY_SEPARATOR);
			self::assertSame(realpath($this->scratch), $codec::getTempDirectory(), "{$codec} stores the canonical path.");

			$codec::setTempDirectory(null);
			self::assertNull($codec::getTempDirectory(), "{$codec} restores the default.");
		}
	}

	public function testTheDirectoryIsPerCodecClass(): void
	{
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
		foreach (self::CODECS as $codec) {
			if ($codec !== TGzipFallbackCompressor::class) {
				self::assertNull($codec::getTempDirectory(), "Setting the gzip directory leaves {$codec} untouched.");
			}
		}
	}

	public function testAMissingPathIsRejected(): void
	{
		foreach (self::CODECS as $codec) {
			foreach (['', $this->scratch . DIRECTORY_SEPARATOR . 'absent'] as $path) {
				try {
					$codec::setTempDirectory($path);
					self::fail('Expected a TInvalidDataValueException.');
				} catch (TInvalidDataValueException $e) {
					self::assertSame('fallbackcompressor_tempdirectory_invalid', $e->getErrorCode(), "{$codec} rejects '{$path}'.");
				}
			}
			self::assertNull($codec::getTempDirectory(), 'A rejected path leaves the setting unchanged.');
		}
	}

	public function testAPlainFilePathIsRejected(): void
	{
		$file = $this->scratch . DIRECTORY_SEPARATOR . 'file';
		self::assertNotFalse(file_put_contents($file, 'x'));
		$this->expectException(TInvalidDataValueException::class);
		TGzipFallbackCompressor::setTempDirectory($file);
	}

	public function testAnUnwritableDirectoryIsRejected(): void
	{
		self::assertTrue(chmod($this->scratch, 0o500));
		if (is_writable($this->scratch)) {
			self::markTestSkipped('The directory stays writable to this user (root ignores modes).');
		}
		$this->expectException(TInvalidDataValueException::class);
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
	}

	public function testStagingUsesTheConfiguredDirectoryAndCleansUp(): void
	{
		if (!$this->onPath('gzip')) {
			self::markTestSkipped('The gzip command is not on the PATH.');
		}
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);

		$data = str_repeat('configured staging ', 500) . random_bytes(128);
		self::assertSame($data, TStageRecorderGzipCompressor::decompress(TStageRecorderGzipCompressor::compress($data)), 'The command round-trips through the configured directory.');

		self::assertNotEmpty(TStageRecorderGzipCompressor::$staged, 'The run staged files through the configured directory hook.');
		foreach (TStageRecorderGzipCompressor::$staged as $path) {
			self::assertSame(realpath($this->scratch), dirname($path), 'Every staging file was created in the configured directory.');
			self::assertFileDoesNotExist($path, 'Every staging file was removed after the run.');
		}
	}

	public function testStagingDefaultsToTheSystemTemporaryDirectory(): void
	{
		$path = static::invoke(TGzipFallbackCompressor::class, 'cliTempFile');
		try {
			self::assertSame(realpath(sys_get_temp_dir()), realpath(dirname($path)), 'With no directory configured the file is in the system temporary directory.');
		} finally {
			@unlink($path);
		}
	}

	public function testADirectoryThatDisappearsFailsClosed(): void
	{
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
		self::assertTrue(rmdir($this->scratch));
		$this->assertThrowsIOCode('fallbackcompressor_tempdirectory_invalid', fn () => static::invoke(TGzipFallbackCompressor::class, 'cliTempFile'));
	}

	public function testADirectoryThatBecomesUnwritableFailsClosed(): void
	{
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
		self::assertTrue(chmod($this->scratch, 0o500));
		if (is_writable($this->scratch)) {
			self::markTestSkipped('The directory stays writable to this user (root ignores modes).');
		}
		$this->assertThrowsIOCode('fallbackcompressor_tempdirectory_invalid', fn () => static::invoke(TGzipFallbackCompressor::class, 'cliTempFile'));
	}

	public function testAStagingFileOutsideTheDirectoryIsRemovedAndRejected(): void
	{
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
		$this->assertThrowsIOCode('fallbackcompressor_tempdirectory_escaped', fn () => static::invoke(TEscapedStageGzipCompressor::class, 'cliTempFile'));
		self::assertIsString(TEscapedStageGzipCompressor::$lastStaged, 'The hook created a file outside the directory.');
		self::assertFileDoesNotExist(TEscapedStageGzipCompressor::$lastStaged, 'The escaped staging file was removed.');
	}

	public function testAStagingFileThatCannotBeCreatedThrows(): void
	{
		TGzipFallbackCompressor::setTempDirectory($this->scratch);
		$this->assertThrowsIOCode('clicompressor_process_failed', fn () => static::invoke(TFailingStageGzipCompressor::class, 'cliTempFile'));

		TGzipFallbackCompressor::setTempDirectory(null);
		$this->assertThrowsIOCode('clicompressor_process_failed', fn () => static::invoke(TFailingStageGzipCompressor::class, 'cliTempFile'));
	}
}
