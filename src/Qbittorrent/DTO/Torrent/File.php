<?php

namespace Blackout\Qbittorrent\DTO\Torrent;

use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\Enum\FilePriority;

use Blackout\Helper;

final class File extends Base
{
	public int $index;
	public string $name;
	public int $size;
	public float $progress;
	public FilePriority $priority;
	public bool $is_seed;
	public float $availability;
	public array $piece_range;

	public float $progress_percent { get => round($this->progress * 100, 2); }
	public bool $is_complete { get => $this->progress >= 1; }
		public string $size_human { get => Helper::filesize($this->size); }
	public string $basename { get => basename($this->name); }
	public string $dirname { get => dirname($this->name); }
	public string $url_path { get => implode('/', array_map('rawurlencode', explode('/', $this->name))); }
}
