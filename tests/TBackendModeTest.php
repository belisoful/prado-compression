<?php

/**
 * TBackendModeTest class file.
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
use Prado\IO\Compression\Tests\Fixtures\TCliMarkerGzipCompressor;
use Prado\IO\Compression\Tests\Fixtures\TNoCliGzipCompressor;
use Prado\IO\Compression\Tests\Fixtures\TNoPhpCliMarkerGzipCompressor;
use Prado\IO\Compression\Tests\Fixtures\TNoPhpNoCliGzipCompressor;

/**
 * TBackendModeTest class.
 *
 * Exercises the `BackendMode` property of the fallback codecs: the default, the setter's
 * validation, the per-class isolation, and the backend each mode routes a call to.  The
 * routing is observed through the fixtures in `tests/Fixtures/` — a command backend that
 * returns a marker instead of running a command, and no-backend doubles whose error codes
 * name the backend that failed — so the routing tests need no command installed and cannot
 * be fooled by the two real backends producing interchangeable bytes.  The per-codec
 * round-trip tests then run the real backends under the "only" modes, one mode per backend
 * on all five codecs, skipping where a backend is not installed.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TBackendModeTest extends CompressorTestCase
{
	/** @var array<class-string> Every class with its own copy of the mode property. */
	private const CODECS = [
		TBrotliFallbackCompressor::class,
		TBzip2FallbackCompressor::class,
		TGzipFallbackCompressor::class,
		TXzFallbackCompressor::class,
		TZstdFallbackCompressor::class,
	];

	protected function tearDown(): void
	{
		foreach (self::CODECS as $codec) {
			$codec::setBackendMode(null);
		}
		parent::tearDown();
	}

	private function requireZlib(): void
	{
		if (!extension_loaded('zlib')) {
			self::markTestSkipped('The zlib extension backs the native path of the gzip fixtures.');
		}
	}

	public function testTheDefaultModeIsPhpFirst(): void
	{
		foreach (self::CODECS as $codec) {
			self::assertSame(TFallbackCompressorMode::PhpFirst, $codec::getBackendMode(), "{$codec} defaults to PhpFirst.");
		}
	}

	public function testTheSetterAcceptsEveryModeAndNormalizesItsCase(): void
	{
		foreach ([TFallbackCompressorMode::PhpOnly, TFallbackCompressorMode::CliOnly, TFallbackCompressorMode::PhpFirst, TFallbackCompressorMode::CliFirst] as $mode) {
			TGzipFallbackCompressor::setBackendMode($mode);
			self::assertSame($mode, TGzipFallbackCompressor::getBackendMode());

			TGzipFallbackCompressor::setBackendMode(strtolower($mode));
			self::assertSame($mode, TGzipFallbackCompressor::getBackendMode(), 'Any casing resolves to the canonical mode.');
		}
	}

	public function testNullRestoresTheDefaultMode(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);
		TGzipFallbackCompressor::setBackendMode(null);
		self::assertSame(TFallbackCompressorMode::PhpFirst, TGzipFallbackCompressor::getBackendMode());
	}

	public function testAnInvalidModeThrows(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		TGzipFallbackCompressor::setBackendMode('NativeOnly');
	}

	public function testTheModeIsPerCodecClass(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);
		foreach (self::CODECS as $codec) {
			if ($codec !== TGzipFallbackCompressor::class) {
				self::assertSame(TFallbackCompressorMode::PhpFirst, $codec::getBackendMode(), "Setting the gzip mode leaves {$codec} untouched.");
			}
		}
	}

	public function testPhpFirstUsesThePhpBackendWhenTheExtensionIsLoaded(): void
	{
		$this->requireZlib();
		$packed = TCliMarkerGzipCompressor::compress('backward compatible');
		self::assertNotSame(TCliMarkerGzipCompressor::CLI_MARKER, $packed, 'The default mode does not compress through the command backend.');
		self::assertSame('backward compatible', TCliMarkerGzipCompressor::decompress($packed), 'The default mode does not decompress through the command backend.');
	}

	public function testPhpFirstFallsBackToTheCommandWithoutTheExtension(): void
	{
		self::assertSame(TCliMarkerGzipCompressor::CLI_MARKER, TNoPhpCliMarkerGzipCompressor::compress('data'));
		self::assertSame(TCliMarkerGzipCompressor::CLI_MARKER, TNoPhpCliMarkerGzipCompressor::decompress('data'));
		self::assertTrue(TNoPhpCliMarkerGzipCompressor::isAvailable(), 'Available through the command alone.');
	}

	public function testCliFirstUsesTheCommandBackendEvenWithTheExtensionLoaded(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliFirst);
		self::assertSame(TCliMarkerGzipCompressor::CLI_MARKER, TCliMarkerGzipCompressor::compress('data'));
		self::assertSame(TCliMarkerGzipCompressor::CLI_MARKER, TCliMarkerGzipCompressor::decompress('data'));
	}

	public function testCliFirstFallsBackToThePhpBackendWithoutTheCommand(): void
	{
		$this->requireZlib();
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliFirst);
		$packed = TNoCliGzipCompressor::compress('cli first, php second');
		self::assertSame('cli first, php second', TNoCliGzipCompressor::decompress($packed));
		self::assertTrue(TNoCliGzipCompressor::isAvailable(), 'Available through the extension alone.');
	}

	public function testPhpOnlyNeverUsesTheCommandBackend(): void
	{
		$this->requireZlib();
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
		$packed = TCliMarkerGzipCompressor::compress('php only');
		self::assertNotSame(TCliMarkerGzipCompressor::CLI_MARKER, $packed);
		self::assertSame('php only', TCliMarkerGzipCompressor::decompress($packed));
	}

	public function testPhpOnlyIsUnavailableAndThrowsWithoutTheExtension(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
		self::assertFalse(TNoPhpCliMarkerGzipCompressor::isAvailable(), 'The command cannot satisfy PhpOnly.');
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TNoPhpCliMarkerGzipCompressor::compress('data'));
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TNoPhpCliMarkerGzipCompressor::decompress('data'));
	}

	public function testCliOnlyUsesTheCommandBackendEvenWithTheExtensionLoaded(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);
		self::assertTrue(TCliMarkerGzipCompressor::isAvailable(), 'The command satisfies CliOnly.');
		self::assertSame(TCliMarkerGzipCompressor::CLI_MARKER, TCliMarkerGzipCompressor::compress('data'));
		self::assertSame(TCliMarkerGzipCompressor::CLI_MARKER, TCliMarkerGzipCompressor::decompress('data'));
	}

	public function testCliOnlyIsUnavailableAndThrowsWithoutTheCommand(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);
		self::assertFalse(TNoCliGzipCompressor::isAvailable(), 'The extension cannot satisfy CliOnly.');
		$this->assertThrowsIOCode('clicompressor_command_missing', fn () => TNoCliGzipCompressor::compress('data'));
		$this->assertThrowsIOCode('clicompressor_command_missing', fn () => TNoCliGzipCompressor::decompress('data'));
	}

	public function testCliFirstThrowsTheExtensionErrorWhenNoBackendExists(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliFirst);
		self::assertFalse(TNoPhpNoCliGzipCompressor::isAvailable(), 'No backend, no availability.');
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TNoPhpNoCliGzipCompressor::compress('data'));
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TNoPhpNoCliGzipCompressor::decompress('data'));
	}

	public function testPhpFirstThrowsTheCommandErrorWhenNoBackendExists(): void
	{
		self::assertFalse(TNoPhpNoCliGzipCompressor::isAvailable(), 'No backend, no availability.');
		$this->assertThrowsIOCode('clicompressor_command_missing', fn () => TNoPhpNoCliGzipCompressor::compress('data'));
		$this->assertThrowsIOCode('clicompressor_command_missing', fn () => TNoPhpNoCliGzipCompressor::decompress('data'));
	}

	/**
	 * No PHP xz extension is published, so this pin is deterministic on every machine: in
	 * PhpOnly mode the xz codec is unavailable and throws even where the `xz` command is
	 * installed.  The xz codec also reaches {@see \Prado\IO\Compression\TBuiltinCompressor}
	 * directly rather than through a concrete native codec, so its PhpOnly path is the one
	 * the gzip fixtures do not represent.  When an extension is adopted, this test is what
	 * changes.
	 */
	public function testPhpOnlyOnXzIsUnavailableUntilAnExtensionExists(): void
	{
		self::assertFalse(extension_loaded('xz'), 'No PHP xz extension is published.');
		TXzFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
		self::assertFalse(TXzFallbackCompressor::isAvailable(), 'The xz command cannot satisfy PhpOnly.');
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TXzFallbackCompressor::compress('data'));
		$this->assertThrowsIOCode('builtincompressor_extension_required', fn () => TXzFallbackCompressor::decompress('data'));
	}

	/**
	 * @return array<string, array{0: class-string, 1: string, 2: string}>
	 */
	public static function codecBackendProvider(): array
	{
		return [
			//           codec                              extension  command
			'gzip' => [TGzipFallbackCompressor::class, 'zlib', 'gzip'],
			'bzip2' => [TBzip2FallbackCompressor::class, 'bz2', 'bzip2'],
			'zstd' => [TZstdFallbackCompressor::class, 'zstd', 'zstd'],
			'brotli' => [TBrotliFallbackCompressor::class, 'brotli', 'brotli'],
			'xz' => [TXzFallbackCompressor::class, 'xz', 'xz'],
		];
	}

	/**
	 * Every codec, not only the gzip fixtures, honors CliOnly through the public calls: the
	 * command backend serves the round trip even where the extension is loaded and would win
	 * under the default mode.
	 * @dataProvider codecBackendProvider
	 * @param class-string $codec
	 * @param string $extension
	 * @param string $command
	 */
	public function testCliOnlyRoundTripsOnEveryCodec(string $codec, string $extension, string $command): void
	{
		if (!$this->onPath($command)) {
			self::markTestSkipped("The {$command} command is not on the PATH.");
		}
		$codec::setBackendMode(TFallbackCompressorMode::CliOnly);
		$data = str_repeat('cli only ', 500) . random_bytes(128);
		self::assertSame($data, $codec::decompress($codec::compress($data)), 'The command backend serves the round trip.');
	}

	/**
	 * Every codec honors PhpOnly through the public calls: the extension serves the round
	 * trip.  The xz entry skips until a PHP xz extension exists.
	 * @dataProvider codecBackendProvider
	 * @param class-string $codec
	 * @param string $extension
	 * @param string $command
	 */
	public function testPhpOnlyRoundTripsOnEveryCodec(string $codec, string $extension, string $command): void
	{
		if (!extension_loaded($extension)) {
			self::markTestSkipped("The {$extension} extension is not loaded.");
		}
		$codec::setBackendMode(TFallbackCompressorMode::PhpOnly);
		$data = str_repeat('php only ', 500) . random_bytes(128);
		self::assertSame($data, $codec::decompress($codec::compress($data)), 'The extension serves the round trip.');
	}

	public function testAvailabilityTracksTheModeOnTheRealCodec(): void
	{
		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::PhpOnly);
		self::assertSame(extension_loaded('zlib'), TGzipFallbackCompressor::isAvailable(), 'PhpOnly availability is the extension check.');

		TGzipFallbackCompressor::setBackendMode(TFallbackCompressorMode::CliOnly);
		self::assertSame($this->onPath('gzip'), TGzipFallbackCompressor::isAvailable(), 'CliOnly availability is the command check.');

		foreach ([TFallbackCompressorMode::PhpFirst, TFallbackCompressorMode::CliFirst] as $mode) {
			TGzipFallbackCompressor::setBackendMode($mode);
			self::assertSame(
				extension_loaded('zlib') || $this->onPath('gzip'),
				TGzipFallbackCompressor::isAvailable(),
				"{$mode} availability is either backend."
			);
		}
	}
}
