<?php

/**
 * ConfigTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression\Tests;

use PHPUnit\Framework\TestCase;
use Prado\IO\Compression\ICompressor;

/**
 * ConfigTest class.
 *
 * Checks the declarative files in `config/` against the code they describe, so the class map
 * cannot drift from `src/` as classes are added or renamed, and so a message added to
 * `errorMessages.txt` cannot be one the framework already defines and would override.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ConfigTest extends TestCase
{
	private const CONFIG_DIR = __DIR__ . '/../config';

	private const SRC_DIR = __DIR__ . '/../src';

	/**
	 * @return array<string, string> The class map, short name to fully qualified name.
	 */
	private static function classMap(): array
	{
		$json = file_get_contents(self::CONFIG_DIR . '/classMap.json');
		self::assertIsString($json, 'config/classMap.json is readable.');
		$map = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($map);
		return $map;
	}

	/**
	 * @return string[] The class short names found in src/.
	 */
	private static function sourceClasses(): array
	{
		$names = [];
		foreach ((array) glob(self::SRC_DIR . '/*.php') as $file) {
			$names[] = basename((string) $file, '.php');
		}
		sort($names);
		return $names;
	}

	public function testTheClassMapCoversEveryClassInSrcAndNothingElse(): void
	{
		self::assertSame(self::sourceClasses(), array_keys(self::classMap()), 'The class map lists exactly the classes in src/, in order.');
	}

	public function testEveryMappedNameResolvesAndEveryCodecIsACompressor(): void
	{
		foreach (self::classMap() as $short => $fqn) {
			self::assertTrue(class_exists($fqn) || trait_exists($fqn), "{$fqn} is autoloadable.");
			self::assertSame($short, substr($fqn, strrpos($fqn, '\\') + 1), 'The short name is the class basename.');
			if (str_ends_with($short, 'Compressor')) {
				self::assertContains(ICompressor::class, class_implements($fqn), "{$fqn} is a codec.");
			}
		}
		self::assertCount(5, preg_grep('/Compressor$/', array_keys(self::classMap())), 'The map names the five codecs.');
	}

	/**
	 * The map is what `Prado::registerClassMap()` consumes, so it has to survive the round trip
	 * through JSON with its namespace separators intact.
	 */
	public function testTheClassMapIsRegisterableAsIs(): void
	{
		$map = self::classMap();
		self::assertNotEmpty($map);
		foreach ($map as $fqn) {
			self::assertStringStartsWith('Prado\\IO\\Compression\\', $fqn, 'The map holds fully qualified names, not escaped fragments.');
		}
	}

	/**
	 * The framework's own messages.txt is consulted last and wins, so a key restated here would
	 * never be read.  A message file that shadows a core key is dead weight and a trap.
	 */
	public function testNoMessageRestatesAKeyTheFrameworkAlreadyDefines(): void
	{
		$ours = $this->parseMessages(self::CONFIG_DIR . '/errorMessages.txt');
		$core = $this->parseMessages(dirname((new \ReflectionClass(\Prado\Prado::class))->getFileName()) . '/Exceptions/messages/messages.txt');

		self::assertNotEmpty($core, 'The framework message file was located.');
		self::assertSame([], array_intersect(array_keys($ours), array_keys($core)), 'No key here is one the framework already defines.');
	}

	/**
	 * Parses a message file the way `TException::translateErrorMessage()` does.
	 * @param string $file The message file path.
	 * @return array<string, string> The key to message map.
	 */
	private function parseMessages(string $file): array
	{
		$messages = [];
		foreach ((array) file($file) as $line) {
			$line = trim((string) $line);
			if ($line === '' || strncmp($line, '#', 1) === 0 || strncmp($line, ';', 1) === 0) {
				continue;
			}
			if (strpos($line, '=') !== false) {
				[$code, $message] = explode('=', $line, 2);
				$messages[trim($code)] = trim($message);
			}
		}
		return $messages;
	}
}
