<?php

namespace Blackout\Qbittorrent\DTO;

final class MainData extends Base
{
	public int $rid;
	public bool $full_update;
	public array $torrents;
	public array $torrents_removed;
	public array $categories;
	public array $categories_removed;
	public array $tags;
	public array $tags_removed;
	public array $trackers;
	public array $trackers_removed;
	public array $server_state;
}
