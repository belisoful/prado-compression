<?php

/**
 * TFallbackCompressorMode class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TFallbackCompressorMode class.
 *
 * TFallbackCompressorMode defines the backend selection modes a fallback codec accepts
 * through {@see TFallbackCompressorTrait::setBackendMode()}.  Each mode names which of
 * the two backends — the native PHP extension and the system command — a codec may use,
 * and in what order:
 * - PhpOnly: the extension is the only backend; the command never runs.
 * - CliOnly: the command is the only backend; the extension is never used.
 * - PhpFirst: the extension when it is loaded, otherwise the command.  The default.
 * - CliFirst: the command when it is present, otherwise the extension.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.1.0
 */
class TFallbackCompressorMode extends \Prado\TEnumerable
{
	public const PhpOnly = 'PhpOnly';
	public const CliOnly = 'CliOnly';
	public const PhpFirst = 'PhpFirst';
	public const CliFirst = 'CliFirst';
}
