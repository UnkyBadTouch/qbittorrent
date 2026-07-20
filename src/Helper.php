<?php

namespace Blackout;

use Noodlehaus\Config;

class Helper
{
	// Loads every *.php file in conf/ (each returning its own top-level key, e.g.
	// conf/qbittorrent.php returns ['qbittorrent' => [...]]). Any conf/*.local.php
	// (per-file credential/override, gitignored — e.g. conf/qbittorrent.local.php
	// overrides conf/qbittorrent.php) loads last, after every base file, so
	// overrides always win regardless of glob order.
	public static function config(string $dir): Config
	{
		$files = glob($dir . '/conf/*.php') ?: [];

		$base  = array_filter($files, fn ($f) => !str_ends_with($f, '.local.php'));
		$local = array_filter($files, fn ($f) => str_ends_with($f, '.local.php'));

		sort($base);
		sort($local);

		return new Config([...$base, ...$local]);
	}

	public static function jsonEncode(mixed $data): string
	{
		$json = json_encode(
			$data,
			JSON_PRETTY_PRINT
			| JSON_UNESCAPED_UNICODE
			| JSON_UNESCAPED_SLASHES
			| JSON_INVALID_UTF8_SUBSTITUTE,
		);

		if ($json === false)
		{
			throw new \Exception('JSON encode failed: ' . json_last_error_msg());
		}

		return $json;
	}

	public static function jsonDecode(string $json, bool $assoc = true): mixed
	{
		$data = json_decode(
			$json,
			$assoc,
			512,
			JSON_INVALID_UTF8_SUBSTITUTE,
		);

		if (json_last_error() !== JSON_ERROR_NONE)
		{
			throw new \Exception('JSON decode failed: ' . json_last_error_msg());
		}

		return $data;
	}

	public static function isCli()
	{
		return PHP_SAPI === 'cli';
	}

	public static function log($txt = '', $vars = [])
	{
		$txt = vsprintf($txt, (array)$vars);
		if (empty($txt)) return;

		printf('[%s] %s%s',
			new \DateTime()->format('Y-m-d H:i:s.u'),
			Helper::isCli() ? strip_tags($txt) : $txt,
			Helper::isCli() ? "\n" : "<br>"
		);

		@flush();
		@ob_flush();
	}

	public static function filesize(int|float $bytes, int $precision = 2): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

		$i = $bytes > 0
		? min((int) floor(log($bytes, 1024)), count($units) - 1)
		: 0;

		return round($bytes / (1024 ** $i), $precision)
		. ' '
		. $units[$i];
	}

	public static function atomicWrite(string $path, string $content): void
	{
		$tmp = $path . '.tmp.' . getmypid();
		file_put_contents($tmp, $content);
		rename($tmp, $path);
	}
}
