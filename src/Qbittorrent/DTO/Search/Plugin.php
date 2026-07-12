<?php

namespace Blackout\Qbittorrent\DTO\Search;

use Blackout\Qbittorrent\DTO\Base;

final class Plugin extends Base
{
	public string $name;
	public string $fullName;
	public string $url;
	public string $version;
	public bool $enabled;

	/** @var array{id: string, name: string}[] */
	public array $supportedCategories;

	public function enable(bool $enable = true): void
	{
		$this->qbittorrent->enableSearchPlugin($this->name, $enable);
		$this->enabled = $enable;
	}

	public function uninstall(): void
	{
		$this->qbittorrent->uninstallSearchPlugin($this->name);
	}
}
