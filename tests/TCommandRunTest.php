<?php

/**
 * TCommandRunTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Compression\TBzip2FallbackCompressor;
use Prado\IO\Compression\TGzipFallbackCompressor;
use Prado\IO\Compression\Tests\Fixtures\TStageIoGzipCompressor;
use Prado\IO\Compression\TXzFallbackCompressor;
use Prado\IO\Compression\TZstdFallbackCompressor;

/**
 * TCommandRunTest class.
 *
 * The command run under failure and hostile input: an environment that sets the commands'
 * options, a staging file that cannot be written or read, an output past the limit, and a
 * fatal error during the run.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TCommandRunTest extends CompressorTestCase
{
	private const CODECS = [TGzipFallbackCompressor::class, TBzip2FallbackCompressor::class, TXzFallbackCompressor::class, TZstdFallbackCompressor::class];

	private string $stage;

	/** @var string[] The environment variables a test set. */
	private array $variables = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->stage = sys_get_temp_dir() . '/prado_run_' . bin2hex(random_bytes(6));
		mkdir($this->stage, 0o700);
	}

	protected function tearDown(): void
	{
		foreach ($this->variables as $name) {
			putenv($name);
		}
		foreach (self::CODECS as $codec) {
			$codec::setBackendMode(null);
			$codec::setTempDirectory(null);
			$codec::setMaxOutputSize(null);
		}
		TStageIoGzipCompressor::reset();
		array_map('unlink', glob($this->stage . '/*') ?: []);
		rmdir($this->stage);
		parent::tearDown();
	}

	private function setVariable(string $name, string $value): void
	{
		putenv("{$name}={$value}");
		$this->variables[] = $name;
	}

	private function skipWithoutCommand(string $command): void
	{
		if (!$this->onPath($command)) {
			self::markTestSkipped("The {$command} command is not available.");
		}
	}

	public function testTheEnvironmentDropsOnlyTheOptionVariables(): void
	{
		$this->setVariable('XZ_OPT', '--format=lzma');
		$this->setVariable('bzip2', '-s');
		$this->setVariable('PRADO_KEPT', 'kept');

		$environment = static::invoke(TGzipFallbackCompressor::class, 'cliEnvironment');

		self::assertArrayNotHasKey('XZ_OPT', $environment);
		self::assertArrayNotHasKey('bzip2', $environment, 'The names match in any case, as on Windows.');
		self::assertSame('kept', $environment['PRADO_KEPT'], 'Every other variable reaches the command.');
		self::assertSame(getenv('PATH'), $environment['PATH']);
	}

	public function testXzOptionVariablesCannotChangeTheFormat(): void
	{
		$this->skipWithoutCommand('xz');
		$this->setVariable('XZ_OPT', '--format=lzma');
		$this->setVariable('XZ_DEFAULTS', '--format=lzma');

		$packed = static::invoke(TXzFallbackCompressor::class, 'cliCompress', 'data', 6);

		self::assertSame("\xFD7zXZ\x00", substr($packed, 0, 6));
	}

	public function testBzip2OptionVariablesCannotChangeTheBlockSize(): void
	{
		$this->skipWithoutCommand('bzip2');
		$this->setVariable('BZIP2', '-s');
		$this->setVariable('BZIP', '-s');

		self::assertSame('BZh9', substr(static::invoke(TBzip2FallbackCompressor::class, 'cliCompress', 'data', 9), 0, 4));
	}

	public function testOptionVariablesCannotChangeTheDefaultLevel(): void
	{
		$data = str_repeat('option variables ', 2000);
		$this->skipWithoutCommand('gzip');
		$this->skipWithoutCommand('zstd');
		foreach ([[TGzipFallbackCompressor::class, 'gzip', 'GZIP', '-1'], [TZstdFallbackCompressor::class, 'zstd', 'ZSTD_CLEVEL', '1']] as [$codec, $command, $name, $value]) {
			$expected = static::invoke($codec, 'cliCompress', $data, -1);
			$this->setVariable($name, $value);
			self::assertSame($expected, static::invoke($codec, 'cliCompress', $data, -1), "{$name} does not reach {$command}.");
		}
	}

	public function testGzipCommandOutputIsDeterministic(): void
	{
		$this->skipWithoutCommand('gzip');
		$first = static::invoke(TGzipFallbackCompressor::class, 'cliCompress', 'same input', 6);
		sleep(1);
		self::assertSame($first, static::invoke(TGzipFallbackCompressor::class, 'cliCompress', 'same input', 6), 'No staging file time or name is in the header.');
	}

	public function testAShortWriteOfTheInputThrowsAndRemovesTheStagingFiles(): void
	{
		$this->skipWithoutCommand('gzip');
		TStageIoGzipCompressor::setTempDirectory($this->stage);
		foreach ([0, 3, false] as $result) {
			TStageIoGzipCompressor::$writeResult = $result;
			$this->assertThrowsIOCode('fallbackcompressor_stagefile_write_failed', fn () => static::invoke(TStageIoGzipCompressor::class, 'cliCompress', 'data to stage', 6));
			self::assertSame([], glob($this->stage . '/*'));
		}
	}

	public function testAFailedReadOfTheOutputThrowsAndRemovesTheStagingFiles(): void
	{
		$this->skipWithoutCommand('gzip');
		TStageIoGzipCompressor::setTempDirectory($this->stage);
		TStageIoGzipCompressor::$readFails = true;
		$this->assertThrowsIOCode('fallbackcompressor_stagefile_read_failed', fn () => static::invoke(TStageIoGzipCompressor::class, 'cliCompress', 'data', 6));
		self::assertSame([], glob($this->stage . '/*'));
	}

	public function testACommandThatCannotStartThrowsAndRemovesTheStagingFiles(): void
	{
		$this->skipWithoutCommand('gzip');
		TStageIoGzipCompressor::setTempDirectory($this->stage);
		TStageIoGzipCompressor::$writeRemoves = true;
		$this->assertThrowsIOCode('clicompressor_process_failed', fn () => static::invoke(TStageIoGzipCompressor::class, 'cliCompress', 'data', 6));
		self::assertSame([], glob($this->stage . '/*'));
	}

	public function testTheMaxOutputSizeDefaultsToNoneAndIsPerCodec(): void
	{
		self::assertNull(TGzipFallbackCompressor::getMaxOutputSize());
		TGzipFallbackCompressor::setMaxOutputSize(1024);
		self::assertSame(1024, TGzipFallbackCompressor::getMaxOutputSize());
		self::assertNull(TXzFallbackCompressor::getMaxOutputSize(), 'Each codec class has its own limit.');
		TGzipFallbackCompressor::setMaxOutputSize(null);
		self::assertNull(TGzipFallbackCompressor::getMaxOutputSize());
	}

	public function testANegativeMaxOutputSizeIsRejected(): void
	{
		$this->expectException(TInvalidDataValueException::class);
		TGzipFallbackCompressor::setMaxOutputSize(-1);
	}

	/**
	 * A stream that expands past the limit stops the command, raises the limit's exception, and
	 * leaves nothing in the staging directory.
	 */
	public function testAnOutputPastTheLimitStopsTheCommand(): void
	{
		$this->skipWithoutCommand('gzip');
		$packed = static::invoke(TGzipFallbackCompressor::class, 'cliCompress', str_repeat("\0", 32 * 1024 * 1024), 6);
		TGzipFallbackCompressor::setTempDirectory($this->stage);
		TGzipFallbackCompressor::setMaxOutputSize(1024 * 1024);

		$this->assertThrowsIOCode('fallbackcompressor_output_too_large', fn () => static::invoke(TGzipFallbackCompressor::class, 'cliDecompress', $packed));

		self::assertSame([], glob($this->stage . '/*'));
	}

	/**
	 * Under a limit the run reports what it does without one: the output, or the command's failure.
	 */
	public function testARunWithinTheLimitBehavesAsWithoutOne(): void
	{
		$this->skipWithoutCommand('gzip');
		$data = str_repeat('within the limit ', 100);
		$packed = static::invoke(TGzipFallbackCompressor::class, 'cliCompress', $data, 6);
		TGzipFallbackCompressor::setMaxOutputSize(strlen($data));

		self::assertSame($data, static::invoke(TGzipFallbackCompressor::class, 'cliDecompress', $packed), 'An output of exactly the limit passes.');
		$this->assertThrowsIOCode('clicompressor_command_failed', fn () => static::invoke(TGzipFallbackCompressor::class, 'cliDecompress', 'not a gzip stream'));
	}

	/**
	 * A fatal error skips `finally`, so without the shutdown function all three staging files
	 * and the plaintext in them would stay on disk.
	 */
	public function testAFatalErrorDuringARunLeavesNoStagingFiles(): void
	{
		$this->skipWithoutCommand('gzip');
		$command = [PHP_BINARY, __DIR__ . '/Fixtures/fatalDuringCommand.php', $this->stage];
		$process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		self::assertIsResource($process);

		self::assertSame(255, proc_close($process), 'The script ended in a fatal error.');
		self::assertSame([], glob($this->stage . '/*'));
	}

	/**
	 * The shutdown function removes the files this process owns and leaves a parent's, which a
	 * forked child inherits in the list.
	 */
	public function testTheCleanupRemovesOnlyThisProcesssFiles(): void
	{
		$mine = tempnam($this->stage, 'mine');
		$parents = tempnam($this->stage, 'parent');
		$list = new \ReflectionProperty(TGzipFallbackCompressor::class, '_stagedFiles');
		$list->setValue(null, [$mine => getmypid(), $parents => getmypid() + 1]);

		static::invoke(TGzipFallbackCompressor::class, 'cliRemoveStagedFiles');

		self::assertFileDoesNotExist($mine);
		self::assertFileExists($parents);
		$list->setValue(null, []);
	}
}
