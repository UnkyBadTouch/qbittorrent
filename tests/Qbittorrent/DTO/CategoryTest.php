<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Category;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
	public function testHydratesFromTheCategoriesResponse(): void
	{
		$category = new Category(
            [
			'name'                     => 'linux',
			'savePath'                 => '/downloads/linux',
			'download_path'            => '/incomplete/linux',
			'ratio_limit'              => 2.0,
			'seeding_time_limit'       => 3600,
			'inactive_seeding_time_limit' => 1800,
			'share_limit_action'       => 'Stop',
			'share_limits_mode'        => 'MatchAll',
            ]
		);

		$this->assertSame('linux', $category->name);
		$this->assertSame('/downloads/linux', $category->savePath);
		$this->assertSame('/incomplete/linux', $category->download_path);
		$this->assertSame(2.0, $category->ratio_limit);
		$this->assertSame(3600, $category->seeding_time_limit);
		$this->assertSame(1800, $category->inactive_seeding_time_limit);
		$this->assertSame(\Blackout\Qbittorrent\Enum\ShareLimitAction::STOP, $category->share_limit_action);
		$this->assertSame(\Blackout\Qbittorrent\Enum\ShareLimitsMode::MATCH_ALL, $category->share_limits_mode);
	}

	/**
	 * The built-in RSS category is the one case where download_path comes back
	 * null, so the property has to be nullable or hydration fatals.
	 */
	public function testDownloadPathMayBeNull(): void
	{
		$category = new Category(['name' => 'RSS', 'savePath' => '', 'download_path' => null]);

		$this->assertNull($category->download_path);
		$this->assertSame('', $category->savePath);
	}

	public function testEditForwardsTheNameAndUpdatesTheLocalField(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('editCategory')
			->with('linux', '/new/path');

		$category = new Category(['name' => 'linux', 'savePath' => '/old'], $client);

		$category->edit('/new/path');

		$this->assertSame('/new/path', $category->savePath);
	}

	public function testDeleteForwardsTheName(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('deleteCategories')
			->with('linux');

		(new Category(['name' => 'linux'], $client))->delete();
	}

	public function testRatioLimitSerialisesAsAFloatEvenAtItsIntegralDefault(): void
	{
		// qBittorrent returns max_ratio as 0 (an int) when unset, but the real
		// field is fractional, so the float cast must not be skipped.
		$category = new Category(['name' => 'x', 'ratio_limit' => 0]);

		$this->assertSame(0.0, $category->ratio_limit);
		$this->assertSame(['ratio_limit' => 0.0], array_intersect_key(
			$category->jsonSerialize(),
			['ratio_limit' => null],
		));
	}
}
