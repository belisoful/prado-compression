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
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.1.0
 */
trait TFallbackCompressorTrait
{
	use TCliCompressorTrait;

	/** @var string The backend selection mode of this codec class, a {@see TFallbackCompressorMode} value. */
	private static string $_backendMode = TFallbackCompressorMode::PhpFirst;

	/** @var ?string The canonical staging directory of this codec class's command backend, or null for the system temporary directory. */
	private static ?string $_tempDirectory = null;

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
