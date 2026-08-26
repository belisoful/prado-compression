<?php

/**
 * TFallbackCompressorTrait trait file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

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
 * Each codec class carries its own copy of the property, so setting the gzip mode leaves
 * the zstd mode untouched; a subclass of a codec shares the copy of the codec it extends.
 *
 * The command backend stages the data through files in the system temporary directory
 * (see {@see TCliCompressorTrait::cliRun()}), which on a shared or misconfigured system
 * can expose the data to other local users; PhpOnly keeps sensitive data out of the
 * command path entirely.  The README's "Securing the command backend" section documents
 * the safe setup.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.1.0
 */
trait TFallbackCompressorTrait
{
	use TCliCompressorTrait;

	/** @var string The backend selection mode of this codec class, a {@see TFallbackCompressorMode} value. */
	private static string $_backendMode = TFallbackCompressorMode::PhpFirst;

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
}
