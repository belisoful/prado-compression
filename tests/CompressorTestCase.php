<?php

/**
 * CompressorTestCase class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use PHPUnit\Framework\TestCase;

/**
 * CompressorTestCase class.
 *
 * The shared base for the codec tests.  It reaches the protected command backend a codec
 * inherits from `TCliCompressorTrait`, so the fallback path is testable on a machine whose
 * native extension is loaded and would otherwise shadow it, and it locates a command on the
 * `PATH` independently of the trait, so an availability assertion is not tautological.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
abstract class CompressorTestCase extends TestCase
{
	/**
	 * Calls a protected or private static method of a codec.
	 * @param class-string $class The codec class.
	 * @param string $method The static method name.
	 * @param mixed ...$args The arguments.
	 * @return mixed The method's return value.
	 */
	protected static function invoke(string $class, string $method, mixed ...$args): mixed
	{
		return (new \ReflectionMethod($class, $method))->invoke(null, ...$args);
	}

	/**
	 * Returns whether an executable of the given name is on the `PATH`.  The scan is written
	 * out here rather than delegated to the codec, so a test that compares this against the
	 * codec's own answer compares two independent implementations.
	 * @param string $command The command name.
	 * @return bool Whether the command is on the `PATH`.
	 */
	protected function onPath(string $command): bool
	{
		foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
			if ($dir === '') {
				continue;
			}
			$candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $command;
			if (is_file($candidate) && is_executable($candidate)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs a command directly, outside the codec, to cross-check the codec's bytes against
	 * the standalone tool.
	 * @param string[] $argv The command and its arguments.
	 * @param string $input The bytes to feed on standard input.
	 * @return string The command's standard output.
	 */
	protected function rawCli(array $argv, string $input): string
	{
		$process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$out = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		return $out;
	}
}
