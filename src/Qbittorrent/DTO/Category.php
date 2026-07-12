<?php

namespace Blackout\Qbittorrent\DTO;

use Blackout\Qbittorrent\Enum\ShareLimitAction;
use Blackout\Qbittorrent\Enum\ShareLimitsMode;

final class Category extends Base
{
	public string $name;
	public string $savePath;
	public ?string $download_path;

	public float $ratio_limit;
	public int $seeding_time_limit;
	public int $inactive_seeding_time_limit;
	public ShareLimitAction $share_limit_action;
	public ShareLimitsMode $share_limits_mode;

	public function edit(string $savePath): void
	{
		$this->qbittorrent->editCategory($this->name, $savePath);
		$this->savePath = $savePath;
	}

	public function delete(): void
	{
		$this->qbittorrent->deleteCategories($this->name);
	}
}
