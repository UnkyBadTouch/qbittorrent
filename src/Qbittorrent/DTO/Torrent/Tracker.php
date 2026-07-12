<?php

namespace Blackout\Qbittorrent\DTO\Torrent;

use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\Enum\TrackerStatus;

final class Tracker extends Base
{
	public string $url;
	public TrackerStatus $status;

	public int $tier;
	public int $num_peers;
	public int $num_seeds;
	public int $num_leeches;
	public int $num_downloaded;

	public string $msg;

	// only present for multi-tracker (v2) entries with per-endpoint status
	public string $name;
	public bool $updating;
	public int $bt_version;
	public int $next_announce;
	public int $min_announce;
	public array $endpoints;
}
