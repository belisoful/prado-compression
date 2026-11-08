<?php

/**
 * Exhausts the memory limit while reading a command's output, a fatal error `finally` does not
 * survive.  Run by TCommandRunTest in its own process, with the staging directory as argument.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-compression
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

use Prado\IO\Compression\TGzipFallbackCompressor;

require __DIR__ . '/../../vendor/autoload.php';

ini_set('memory_limit', '-1');
ini_set('display_errors', '0');
TGzipFallbackCompressor::setBackendMode('CliOnly');
$bomb = TGzipFallbackCompressor::compress(str_repeat("\0", 64 * 1024 * 1024));
TGzipFallbackCompressor::setTempDirectory($argv[1]);
gc_collect_cycles();
ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
TGzipFallbackCompressor::decompress($bomb);
