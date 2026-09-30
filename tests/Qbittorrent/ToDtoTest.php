<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\DTO\Category;
use Blackout\Qbittorrent\DTO\Cookie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * toDto() and requestDto() are protected, so this reaches them the same way a
 * subclass would — a reflection call rather than a test-only public shim.
 */
#[CoversClass(Client::class)]
final class ToDtoTest extends TestCase
{
	/**
	 * [payload shape, expected result kind].
	 *
	 * The three branches are the whole point: a list of rows, a
	 * hash-keyed map of rows, and a single object. Getting the first two
	 * backwards would hand callers a nested array instead of DTOs.
	 */
	#[DataProvider('shapeProvider')]
	public function testToDtoHandlesEveryResponseShape(array $data, string $expected): void
	{
		$result = $this->toDto(Category::class, $data);

		if ($expected === 'single') {
			$this->assertInstanceOf(Category::class, $result);
			$this->assertSame('linux', $result->name);

			return;
		}

		$this->assertIsArray($result);
		$this->assertContainsOnlyInstancesOf(Category::class, $result);
		$this->assertSame($expected, implode(',', array_keys($result)));
	}

	public static function shapeProvider(): array
	{
		return [
			'list of rows' => [
				[
					['name' => 'a'],
					['name' => 'b'],
				],
				'0,1',
			],
			'hash keyed map' => [
				[
					'linux'  => ['name' => 'linux'],
					'windows' => ['name' => 'windows'],
				],
				'linux,windows',
			],
			'single object' => [
				['name' => 'linux'],
				'single',
			],
			'empty list' => [
				[],
				'',
			],
		];
	}

	/**
	 * The hash-keyed branch must not flatten the API's own keys: /torrents/categories
	 * returns {name: {...}}, and callers index by category name.
	 */
	public function testHashKeyedMapKeepsItsKeys(): void
	{
		$result = $this->toDto(Category::class, [
			'linux'   => ['name' => 'linux', 'savePath' => '/l'],
			'windows' => ['name' => 'windows', 'savePath' => '/w'],
		]);

		$this->assertSame(['linux', 'windows'], array_keys($result));
		$this->assertSame('/l', $result['linux']->savePath);
		$this->assertSame('/w', $result['windows']->savePath);
	}

	public function testToDtoPassesTheClientToEveryConstructedDto(): void
	{
		$client = new Client('http://qbt.test:8080', 'u', 'p');

		$result = $this->invokeToDto($client, Category::class, [
			['name' => 'a'],
			['name' => 'b'],
		]);

		foreach ($result as $category) {
			$injected = (new \ReflectionProperty($category, 'qbittorrent'))->getValue($category);

			$this->assertSame($client, $injected);
		}
	}

	public function testToDtoInjectsTheClientIntoASingleObjectToo(): void
	{
		$client = new Client('http://qbt.test:8080', 'u', 'p');

		$category = $this->invokeToDto($client, Category::class, ['name' => 'a']);

		$this->assertSame(
			$client,
			(new \ReflectionProperty($category, 'qbittorrent'))->getValue($category),
		);
	}

	/**
	 * A single-element list is still a list, so it must come back as a
	 * collection rather than collapsing to one object.
	 */
	public function testSingleElementListStaysACollection(): void
	{
		$result = $this->toDto(Category::class, [['name' => 'only']]);

		$this->assertIsArray($result);
		$this->assertArrayHasKey(0, $result);
		$this->assertInstanceOf(Category::class, $result[0]);
	}

	public function testToDtoHydratesEnumFieldsWhileConverting(): void
	{
		$category = $this->toDto(Category::class, [
			'name'                => 'linux',
			'share_limit_action'  => 'Remove',
		]);

		$this->assertSame(
			\Blackout\Qbittorrent\Enum\ShareLimitAction::REMOVE,
			$category->share_limit_action,
		);
	}

	public function testRequestDtoConvertsTheDecodedResponse(): void
	{
		// A Cookie list, as /app/cookies returns.
		$cookies = $this->toDto(Cookie::class, [
			['name' => 'SID', 'value' => 'abc'],
			['name' => 'other', 'value' => 'def'],
		]);

		$this->assertCount(2, $cookies);
		$this->assertInstanceOf(Cookie::class, $cookies[0]);
		$this->assertSame('SID', $cookies[0]->name);
	}

	public function testToDtoIsCallableOnAnySubclass(): void
	{
		// The method is protected, not private, so the tools repo can reuse it.
		$method = new ReflectionMethod(Client::class, 'toDto');

		$this->assertTrue($method->isProtected());
		$this->assertFalse($method->isStatic());
	}

	public function testBuildMultipartOptionsConvertsAnAssocArrayToGuzzleParts(): void
	{
		$parts = $this->invokeBuildMultipartOptions(
            ['savepath' => '/data', 'category' => 'linux']
		);

		$this->assertSame(
            [
			['name' => 'savepath', 'contents' => '/data'],
			['name' => 'category', 'contents' => 'linux'],
            ],
            $parts,
		);
	}

	public function testBuildMultipartOptionsOnEmptyArray(): void
	{
		$this->assertSame([], $this->invokeBuildMultipartOptions([]));
	}

	private function toDto(string $class, array $data): object|array
	{
		return $this->invokeToDto(new Client('http://qbt.test:8080', 'u', 'p'), $class, $data);
	}

	private function invokeToDto(Client $client, string $class, array $data): object|array
	{
		$method = new ReflectionMethod(Client::class, 'toDto');

		return $method->invoke($client, $class, $data);
	}

	private function invokeBuildMultipartOptions(array $options): array
	{
		$method = new ReflectionMethod(Client::class, 'buildMultipartOptions');

		return $method->invoke(new Client('http://qbt.test:8080', 'u', 'p'), $options);
	}
}
