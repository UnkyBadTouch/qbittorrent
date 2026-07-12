<?php

declare(strict_types=1);

namespace Blackout\Qbittorrent\DTO\Rss;

use Blackout\Qbittorrent\DTO\Base;

final class Feed extends Base
{
	// not an API field — injected by Client::hydrateRssItems() from the
	// item's position in the /rss/items tree, since the API only exposes
	// a feed's name/path as the tree key, never as a field on the feed itself
	public string $path;

	public string $uid;
	public string $url;

	// only emitted when > 0
	public int $refreshInterval;

	// only present when fetched with withData=true
	public string $title;
	public string $lastBuildDate;
	public bool $isLoading;
	public bool $hasError;
	public array $articles;

	public function move(string $destPath): void
	{
		$this->qbittorrent->moveRssItem($this->path, $destPath);
		$this->path = $destPath;
	}

	public function setUrl(string $url): void
	{
		$this->qbittorrent->setRssFeedUrl($this->path, $url);
		$this->url = $url;
	}

	public function setRefreshInterval(int $seconds): void
	{
		$this->qbittorrent->setRssFeedRefreshInterval($this->path, $seconds);
		$this->refreshInterval = $seconds;
	}

	public function markAsRead(?string $articleId = null): void
	{
		$this->qbittorrent->markRssItemAsRead($this->path, $articleId);
	}

	public function refresh(): void
	{
		$this->qbittorrent->refreshRssItem($this->path);
	}

	public function remove(): void
	{
		$this->qbittorrent->removeRssFeed($this->path);
	}
}
