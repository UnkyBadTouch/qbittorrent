<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent;

use Blackout\Qbittorrent\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Helper::class)]
final class HelperTest extends TestCase
{
	/**
	 * mb_convert_encoding()'s substitute here is "?" (0x3F), not U+FFFD — the
	 * latter is what json_encode's JSON_INVALID_UTF8_SUBSTITUTE emits. The two
	 * layers are not interchangeable, so these expectations are pinned to
	 * actual bytes rather than to the more intuitive replacement character.
	 */
	#[DataProvider('invalidUtf8Provider')]
	public function testNormalizeUtf8RepairsInvalidByteSequences(string $input, string $expected): void
	{
		$this->assertSame($expected, Helper::normalizeUtf8($input));
	}

	public static function invalidUtf8Provider(): array
	{
		return [
			'valid ascii'        => ['plain', 'plain'],
			'valid multibyte'    => ['Ünïcödé — ok', 'Ünïcödé — ok'],
			'lone continuation'  => ["\xB1\x31", '?1'],
			'truncated sequence' => ["abc\xE2\x82", 'abc?'],
			'invalid lead byte'  => ["\xFF", '?'],
			'overlong encoding'  => ["\xC0\xAF", '??'],
			'surrogate half'     => ["\xED\xA0\x80", '???'],
			'empty string'       => ['', ''],
		];
	}

	public function testNormalizeUtf8IsIdempotent(): void
	{
		$once = Helper::normalizeUtf8("bad \xB1 bytes");

		$this->assertSame($once, Helper::normalizeUtf8($once));
	}

	public function testNormalizeArrayUtf8RepairsNestedStrings(): void
	{
		$data = [
			'good'   => 'fine',
			'bad'    => "\xB1x",
			'nested' => [
				'deeper'   => "\xFFy",
				'untouched' => 'ok',
			],
			'number' => 42,
			'null'   => null,
		];

		$this->assertSame(
            [
			'good'   => 'fine',
			'bad'    => '?x',
			'nested' => [
				'deeper'   => '?y',
				'untouched' => 'ok',
			],
			'number' => 42,
			'null'   => null,
            ],
            Helper::normalizeArrayUtf8($data),
		);
	}

	public function testNormalizeArrayUtf8LeavesNonStringScalarsAlone(): void
	{
		$input = ['i' => 1, 'f' => 1.5, 'b' => true, 'n' => null];

		$this->assertSame($input, Helper::normalizeArrayUtf8($input));
	}

	public function testNormalizeArrayUtf8OnEmptyArray(): void
	{
		$this->assertSame([], Helper::normalizeArrayUtf8([]));
	}

	public function testExtractKeyFindsTopLevelValues(): void
	{
		$data = [
			['hash' => 'aaa', 'name' => 'one'],
			['hash' => 'bbb', 'name' => 'two'],
		];

		$this->assertSame(['aaa', 'bbb'], Helper::extractKey($data, 'hash'));
	}

	public function testExtractKeyRecursesIntoNestedArrays(): void
	{
		$data = [
			[
				'hash' => 'aaa',
				'trackers' => [
					['url' => 'http://a', 'hash' => 'nested-1'],
					['url' => 'http://b', 'hash' => 'nested-2'],
				],
			],
		];

		$this->assertSame(
			['aaa', 'nested-1', 'nested-2'],
			Helper::extractKey($data, 'hash'),
		);
	}

	public function testExtractKeySkipsEntriesMissingTheKey(): void
	{
		$data = [
			['hash' => 'aaa'],
			['name' => 'no hash here'],
			['hash' => 'bbb'],
		];

		$this->assertSame(['aaa', 'bbb'], Helper::extractKey($data, 'hash'));
	}

	public function testExtractKeyReturnsEmptyArrayWhenKeyIsAbsentEverywhere(): void
	{
		$this->assertSame([], Helper::extractKey([['a' => 1], ['b' => 2]], 'hash'));
	}

	public function testExtractKeyOnEmptyArray(): void
	{
		$this->assertSame([], Helper::extractKey([], 'hash'));
	}

	public function testExtractKeyUnwrapsJsonSerializableValues(): void
	{
		$dto = new class implements \JsonSerializable
		{
			public function jsonSerialize(): mixed
			{
				return ['hash' => 'from-json'];
			}
		};

		$this->assertSame(['from-json'], Helper::extractKey([$dto], 'hash'));
	}

	public function testExtractKeySkipsNonArrayNonSerializableValues(): void
	{
		$this->assertSame(
			['kept'],
			Helper::extractKey(['scalar', 42, null, ['hash' => 'kept']], 'hash'),
		);
	}

	public function testExtractKeysIntersectsAssociativeArray(): void
	{
		$data = ['a' => 1, 'b' => 2, 'c' => 3];

		$this->assertSame(['a' => 1, 'c' => 3], Helper::extractKeys($data, ['a', 'c']));
	}

	public function testExtractKeysAcceptsASingleKeyString(): void
	{
		$this->assertSame(['b' => 2], Helper::extractKeys(['a' => 1, 'b' => 2], 'b'));
	}

	public function testExtractKeysPreservesTheOriginalKeyOrderNotTheRequestedOrder(): void
	{
		$this->assertSame(
			['a' => 1, 'b' => 2],
			Helper::extractKeys(['a' => 1, 'b' => 2], ['b', 'a']),
		);
	}

	public function testExtractKeysAppliesRecursivelyToAListOfArrays(): void
	{
		$data = [
			['hash' => 'a', 'name' => 'one', 'extra' => 1],
			['hash' => 'b', 'name' => 'two', 'extra' => 2],
		];

		$this->assertSame(
			[
				['hash' => 'a', 'name' => 'one'],
				['hash' => 'b', 'name' => 'two'],
			],
			Helper::extractKeys($data, ['hash', 'name']),
		);
	}

	public function testExtractKeysOnEmptyArray(): void
	{
		// An empty array is not a "list of arrays", so it takes the
		// associative branch and intersects to nothing.
		$this->assertSame([], Helper::extractKeys([], ['a']));
	}

	public function testExtractKeysOnAssociativeArrayOfListsIntersectsTopLevel(): void
	{
		// Not a list of arrays (values are lists, the array is associative),
		// so the top-level intersect is what applies.
		$data = [
			'torrents' => [['hash' => 'a']],
			'tags'     => ['x'],
			'other'    => 1,
		];

		$this->assertSame(['torrents' => [['hash' => 'a']]], Helper::extractKeys($data, ['torrents']));
	}

	public function testIsBrowserIsFalseUnderCli(): void
	{
		$this->assertFalse(Helper::isBrowser());
	}

	public function testIsBrowserInvertsIsCli(): void
	{
		$this->assertSame(!\Blackout\Helper::isCli(), Helper::isBrowser());
	}

	public function testExtractKeyAcceptsStdClassLikeObjectsAfterSerialization(): void
	{
		$obj = new stdClass();
		$obj->hash = 'plain';

		// A plain stdClass is not JsonSerializable, so it is skipped rather
		// than treated as a row.
		$this->assertSame([], Helper::extractKey([$obj], 'hash'));
	}
}
