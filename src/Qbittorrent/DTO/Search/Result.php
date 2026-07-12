<?php

namespace Blackout\Qbittorrent\DTO\Search;

use Blackout\Qbittorrent\DTO\Base;

final class Result extends Base
{
	public string $fileName;
	public string $fileUrl;
	public int $fileSize;
	public int $nbSeeders;
	public int $nbLeechers;
	public string $engineName;
	public string $siteUrl;
	public string $descrLink;
	public int $pubDate;

	public function download(): void
	{
		$this->qbittorrent->downloadSearchTorrent($this->fileUrl, $this->engineName);
	}
}
