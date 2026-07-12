<?php

namespace Blackout\Qbittorrent;

class Helper
{
	public static function normalizeUtf8(string $value): string
	{
		if (!mb_check_encoding($value, 'UTF-8'))
		{
			return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		}

		return $value;
	}

	public static function normalizeArrayUtf8(array $data): array
	{
		foreach ($data as $key => $value)
		{
			if (is_string($value))
			{
				$data[$key] = self::normalizeUtf8($value);
			}
			elseif (is_array($value))
			{
				$data[$key] = self::normalizeArrayUtf8($value);
			}
		}

		return $data;
	}

	public static function extractKey(
		array $array,
		string $key,
	): array
	{
		$values = [];

		foreach ($array as $value)
		{
			if ($value instanceof \JsonSerializable)
			{
				$value = $value->jsonSerialize();
			}

			if (! is_array($value))
			{
				continue;
			}

			if (array_key_exists($key, $value))
			{
				$values[] = $value[$key];
			}

			$values = [
				...$values,
				...static::extractKey($value, $key),
			];
		}

		return $values;
	}

	public static function extractKeys(array $data, string|array $keys): array
	{
		// If it's a list of arrays, apply recursively
		if (self::isListOfArrays($data))
		{
			return array_map(
				static function ($item) use ($keys)
				{
					return self::extractKeys($item, (array)$keys);
				},
				$data,
			);
		}

		// Single associative array
		return array_intersect_key(
			$data,
			array_flip((array)$keys),
		);
	}

	private static function isListOfArrays(array $data): bool
	{
		if (!is_array($data) || $data === []) return false;
		foreach ($data as $item) if (!is_array($item)) return false;

		return array_is_list($data);
	}

	public static function isBrowser()
	{
		return !\Blackout\Helper::isCli();
	}
}
