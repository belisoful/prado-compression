<?php

/**
 * TFallbackCompressorTrait trait file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\TPropertyValue;

/**
 * TFallbackCompressorTrait trait.
 *
 * The backend selection shared by the fallback codecs.  A codec has two backends — the
 * native PHP extension of the codec it extends, and the system command it reaches through
 * {@see TCliCompressorTrait} — and this trait decides which one serves a call.  The static
 * {@see setBackendMode() BackendMode} property holds a {@see TFallbackCompressorMode}
 * value per codec class:
 * - {@see TFallbackCompressorMode::PhpOnly} → the extension only; the command never runs.
 * - {@see TFallbackCompressorMode::CliOnly} → the command only; the extension is never used.
 * - {@see TFallbackCompressorMode::PhpFirst} → the extension when loaded, otherwise the command.
 * - {@see TFallbackCompressorMode::CliFirst} → the command when present, otherwise the extension.
 *
 * The default is {@see TFallbackCompressorMode::PhpFirst}, which is the selection the
 * codecs made before the property existed, so existing callers see no change.  The mode
 * routes by availability; it does not retry a call on the other backend after a failure.
 * {@see isAvailable()} reports the backends the mode permits, so a codec in PhpOnly mode
 * without its extension is unavailable even when the command is installed.
 *
 * Each codec class carries its own copy of both properties, so setting the gzip mode or
 * staging directory leaves the zstd codec untouched; a subclass of a codec shares the
 * copies of the codec it extends.
 *
 * The command backend stages the data through temporary files (see
 * {@see TCliCompressorTrait::cliRun()}), which on a shared or misconfigured system can
 * expose the data to other local users.  The static {@see setTempDirectory() TempDirectory}
 * property redirects the staging files of a codec class from the system temporary directory
 * to a private one.  The setting is fail-closed: the directory is validated when set and
 * again at every use, an unusable directory raises a {@see TIOException} instead of falling
 * back to the shared directory, and a staging file that lands outside the directory (the
 * fallback {@see https://www.php.net/tempnam tempnam} performs when its argument is
 * unusable) is removed and reported.  PhpOnly keeps sensitive data out of the command path
 * entirely.  The README's "Securing the command backend" section documents the safe setup.
 *
 * {@see cliRun()} replaces the run of {@see TCliCompressorTrait} to make the command path
 * hold under failure and hostile input:
 * - The command runs without the option variables of {@see CLI_OPTION_VARIABLES}, which would
 *   otherwise change its output format or level.
 * - A short write of the input staging file raises a {@see TIOException} rather than
 *   compressing the part that was written.
 * - A shutdown function removes the staging files of a run ended by a fatal error, where
 *   `finally` does not execute, as a memory limit reached while reading a large output.
 * - The static {@see setMaxOutputSize() MaxOutputSize} property stops a command whose output
 *   exceeds a limit, the guard against a decompression bomb filling the staging disk.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.1.0
 */
trait TFallbackCompressorTrait
{
	use TCliCompressorTrait;

	/** The environment variables that hold default options of the commands. */
	private const CLI_OPTION_VARIABLES = ['BZIP', 'BZIP2', 'GZIP', 'XZ_DEFAULTS', 'XZ_OPT', 'ZSTD_CLEVEL', 'ZSTD_NBTHREADS'];

	/** The interval between checks of the output size, in microseconds. */
	private const CLI_POLL_MICROSECONDS = 10000;

	/** @var string The backend selection mode of this codec class, a {@see TFallbackCompressorMode} value. */
	private static string $_backendMode = TFallbackCompressorMode::PhpFirst;

	/** @var ?string The canonical staging directory of this codec class's command backend, or null for the system temporary directory. */
	private static ?string $_tempDirectory = null;

	/** @var ?int The output limit in bytes of this codec class's command backend, or null for none. */
	private static ?int $_maxOutputSize = null;

	/** @var array<string, int> The staging files of the running commands, path to owning process ID. */
	private static array $_stagedFiles = [];

	/** @var bool Whether the shutdown function that removes staging files is registered. */
	private static bool $_stagedCleanupRegistered = false;

	/**
	 * Returns the backend selection mode of this codec class.
	 * @return string A {@see TFallbackCompressorMode} value; the default is
	 *   {@see TFallbackCompressorMode::PhpFirst}.
	 */
	public static function getBackendMode(): string
	{
		return self::$_backendMode;
	}

	/**
	 * Sets the backend selection mode of this codec class.  The mode is matched against
	 * {@see TFallbackCompressorMode} case-insensitively and stored in canonical form.
	 * @param ?string $mode A {@see TFallbackCompressorMode} value, or null to restore the
	 *   default {@see TFallbackCompressorMode::PhpFirst}.
	 * @throws \Prado\Exceptions\TInvalidDataValueException When the mode is not a
	 *   {@see TFallbackCompressorMode} value.
	 */
	public static function setBackendMode(?string $mode): void
	{
		self::$_backendMode = ($mode === null) ? TFallbackCompressorMode::PhpFirst : TPropertyValue::ensureEnum($mode, TFallbackCompressorMode::class);
	}

	/**
	 * Returns the staging directory of this codec class's command backend.
	 * @return ?string The canonical directory path, or null for the system temporary
	 *   directory.
	 */
	public static function getTempDirectory(): ?string
	{
		return self::$_tempDirectory;
	}

	/**
	 * Sets the staging directory of this codec class's command backend, where the data is
	 * written while a command runs.  The directory is stored in canonical form
	 * ({@see https://www.php.net/realpath realpath}) and must exist and be writable when
	 * set; it is validated again at every use, so a directory that later becomes unusable
	 * raises a {@see TIOException} at the call instead of falling back to the shared system
	 * directory.
	 * @param ?string $path The directory path, or null to restore the system temporary
	 *   directory.
	 * @throws TInvalidDataValueException When the path is not an existing writable
	 *   directory.
	 */
	public static function setTempDirectory(?string $path): void
	{
		if ($path === null) {
			self::$_tempDirectory = null;
			return;
		}
		$real = ($path === '') ? false : realpath($path);
		if ($real === false || !is_dir($real) || !is_writable($real)) {
			throw new TInvalidDataValueException('fallbackcompressor_tempdirectory_invalid', static::NAME, $path);
		}
		self::$_tempDirectory = $real;
	}

	/**
	 * Returns the output limit of this codec class's command backend.
	 * @return ?int The limit in bytes, or null for no limit, the default.
	 */
	public static function getMaxOutputSize(): ?int
	{
		return self::$_maxOutputSize;
	}

	/**
	 * Sets the output limit of this codec class's command backend.  A command whose output
	 * exceeds the limit is stopped and the call raises a {@see TIOException}.  The size is
	 * checked every 10 ms, so the staging file can pass the limit by what the command writes
	 * in that time.  The native backend writes no files and is bound by `memory_limit` instead.
	 * @param ?int $size The limit in bytes, or null for no limit.
	 * @throws TInvalidDataValueException When the size is negative.
	 */
	public static function setMaxOutputSize(?int $size): void
	{
		if ($size !== null && $size < 0) {
			throw new TInvalidDataValueException('fallbackcompressor_maxoutputsize_invalid', static::NAME, $size);
		}
		self::$_maxOutputSize = $size;
	}

	/**
	 * Returns whether a backend the {@see getBackendMode() BackendMode} permits is available.
	 * @return bool Whether the codec can run under the current mode.
	 */
	public static function isAvailable(): bool
	{
		return match (static::getBackendMode()) {
			TFallbackCompressorMode::PhpOnly => static::phpAvailable(),
			TFallbackCompressorMode::CliOnly => static::cliAvailable(),
			default => static::phpAvailable() || static::cliAvailable(),
		};
	}

	/**
	 * Returns whether the native PHP backend of the extended codec is available.
	 * @return bool Whether the native extension is loaded.
	 */
	protected static function phpAvailable(): bool
	{
		return parent::isAvailable();
	}

	/**
	 * Selects the backend for a call under the {@see getBackendMode() BackendMode}: an
	 * "only" mode names its backend unconditionally, so a call on a missing backend fails
	 * with that backend's exception, and a "first" mode yields to the other backend when
	 * its preferred one is absent.
	 * @return bool True for the native PHP backend, false for the command backend.
	 */
	protected static function usePhpBackend(): bool
	{
		return match (static::getBackendMode()) {
			TFallbackCompressorMode::PhpOnly => true,
			TFallbackCompressorMode::CliOnly => false,
			TFallbackCompressorMode::CliFirst => !static::cliAvailable(),
			default => static::phpAvailable(),
		};
	}

	/**
	 * Runs the backing command, replacing {@see TCliCompressorTrait}'s own run; the class
	 * docblock lists what it adds.
	 * @param string[] $args The argument vector after the command name.
	 * @param string $input The bytes to feed to the command.
	 * @throws TIOException When the command is missing, cannot start, exits non-zero, or
	 *   exceeds the output limit, or a staging file cannot be written or read.
	 * @return string The command's standard output.
	 */
	protected static function cliRun(array $args, string $input): string
	{
		$command = static::cliResolveCommand();
		if ($command === null) {
			throw new TIOException('clicompressor_command_missing', static::NAME, implode("', '", static::commands()));
		}
		$staged = [];
		try {
			$staged[] = $stdin = self::cliGuardedTempFile();
			$staged[] = $stdout = self::cliGuardedTempFile();
			$staged[] = $stderr = self::cliGuardedTempFile();
			if (static::cliWriteStage($stdin, $input) !== strlen($input)) {
				throw new TIOException('fallbackcompressor_stagefile_write_failed', static::NAME, $stdin);
			}
			$descriptors = [0 => ['file', $stdin, 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']];
			$process = @proc_open([$command, ...$args], $descriptors, $pipes, null, self::cliEnvironment());
			if (!is_resource($process)) {
				throw new TIOException('clicompressor_process_failed', static::NAME, $command);
			}
			$exit = self::cliWait($process, $stdout);
			if ($exit !== 0) {
				throw new TIOException('clicompressor_command_failed', static::NAME, $command, $exit, trim((string) @file_get_contents($stderr)));
			}
			$output = static::cliReadStage($stdout);
			if ($output === false) {
				throw new TIOException('fallbackcompressor_stagefile_read_failed', static::NAME, $stdout);
			}
			return $output;
		} finally {
			foreach ($staged as $path) {
				@unlink($path);
				unset(self::$_stagedFiles[$path]);
			}
		}
	}

	/**
	 * Waits for a command to finish, enforcing {@see getMaxOutputSize() MaxOutputSize}.
	 * @param resource $process The command's process.
	 * @param string $stdout The staging file of the command's standard output.
	 * @throws TIOException When the output exceeds the limit.
	 * @return int The command's exit code.
	 */
	private static function cliWait($process, string $stdout): int
	{
		$limit = static::getMaxOutputSize();
		if ($limit === null) {
			return proc_close($process);   // blocks until the command finishes; the file descriptors need no draining
		}
		while (true) {
			$status = proc_get_status($process);
			clearstatcache(true, $stdout);
			if ((int) @filesize($stdout) > $limit) {
				proc_terminate($process);
				proc_close($process);
				throw new TIOException('fallbackcompressor_output_too_large', static::NAME, $limit);
			}
			if (!$status['running']) {
				proc_close($process);
				return $status['exitcode'];
			}
			usleep(self::CLI_POLL_MICROSECONDS);
		}
	}

	/**
	 * Returns the command's environment: this process's, without the variables of
	 * {@see CLI_OPTION_VARIABLES}.
	 * @return array<string, string> The environment variables.
	 */
	private static function cliEnvironment(): array
	{
		return array_filter(getenv(), fn ($name) => !in_array(strtoupper($name), self::CLI_OPTION_VARIABLES, true), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * Creates a staging file that the shutdown function removes if the run never ends.
	 * @throws TIOException When the file cannot be created.
	 * @return string The staging file path.
	 */
	private static function cliGuardedTempFile(): string
	{
		$path = self::cliTempFile();
		if (!self::$_stagedCleanupRegistered) {
			self::$_stagedCleanupRegistered = true;
			register_shutdown_function(static fn () => self::cliRemoveStagedFiles());
		}
		self::$_stagedFiles[$path] = getmypid();
		return $path;
	}

	/**
	 * Removes the staging files of the runs a fatal error ended.  A file that another process
	 * owns is left alone, so a forked child that exits does not remove its parent's files.
	 */
	private static function cliRemoveStagedFiles(): void
	{
		foreach (self::$_stagedFiles as $path => $pid) {
			if ($pid === getmypid()) {
				@unlink($path);
				unset(self::$_stagedFiles[$path]);
			}
		}
	}

	/**
	 * Writes the command's input to its staging file.
	 * @param string $path The staging file path.
	 * @param string $data The bytes to write.
	 * @return false|int The number of bytes written, or false on failure.
	 */
	protected static function cliWriteStage(string $path, string $data): false|int
	{
		return @file_put_contents($path, $data);
	}

	/**
	 * Reads the command's output from its staging file.
	 * @param string $path The staging file path.
	 * @return false|string The bytes read, or false on failure.
	 */
	protected static function cliReadStage(string $path): false|string
	{
		return @file_get_contents($path);
	}

	/**
	 * Creates a staging file for one of the command's standard streams, replacing
	 * {@see TCliCompressorTrait}'s own so a {@see setTempDirectory() TempDirectory} is
	 * honored.  With no directory configured the file is created in the system temporary
	 * directory, as the framework does.  With one configured the creation is fail-closed:
	 * the directory is re-validated, and a file created anywhere else — the fallback
	 * {@see https://www.php.net/tempnam tempnam} performs when its argument is unusable —
	 * is removed and reported, so the data never silently reaches a shared directory.
	 * @throws TIOException When the configured directory is not an existing writable
	 *   directory, the file cannot be created, or the file was created outside the
	 *   configured directory.
	 * @return string The staging file path.
	 */
	private static function cliTempFile(): string
	{
		$directory = static::getTempDirectory();
		if ($directory !== null && (!is_dir($directory) || !is_writable($directory))) {
			throw new TIOException('fallbackcompressor_tempdirectory_invalid', static::NAME, $directory);
		}
		$path = static::cliStageFile($directory ?? sys_get_temp_dir());
		if ($path === false) {
			throw new TIOException('clicompressor_process_failed', static::NAME, 'tempnam');
		}
		if ($directory !== null && realpath(dirname($path)) !== $directory) {
			@unlink($path);
			throw new TIOException('fallbackcompressor_tempdirectory_escaped', static::NAME, $path, $directory);
		}
		return $path;
	}

	/**
	 * Creates one staging file in the given directory.  The containment check in
	 * {@see cliTempFile()} verifies the result of a configured directory, so a fallback
	 * into another directory is rejected rather than trusted.
	 * @param string $directory The staging directory.
	 * @return false|string The created file path, or false on failure.
	 */
	protected static function cliStageFile(string $directory): false|string
	{
		return @tempnam($directory, 'prado_cli_');
	}
}
