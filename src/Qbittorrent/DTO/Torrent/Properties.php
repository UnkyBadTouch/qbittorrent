<?php

namespace Blackout\Qbittorrent\DTO\Torrent;

use Blackout\Qbittorrent\DTO\Base;

final class Properties extends Base
{
	public string $hash;
	public string $infohash_v1;
	public string $infohash_v2;
	public string $name;
	public string $comment;
	public string $created_by;
	public int $creation_date;
	public bool $is_private;
	public bool $private;
	public bool $has_metadata;

	public int $piece_size;
	public int $pieces_have;
	public int $pieces_num;
	public int $total_size;
	public int $total_wasted;

	public float $progress;
	public float $availability;
	public float $popularity;
	public float $share_ratio;

	public int $total_downloaded;
	public int $total_downloaded_session;
	public int $total_uploaded;
	public int $total_uploaded_session;
	public int $dl_speed;
	public int $dl_speed_avg;
	public int $up_speed;
	public int $up_speed_avg;
	public int $dl_limit;
	public int $up_limit;

	public int $seeds;
	public int $seeds_total;
	public int $peers;
	public int $peers_total;
	public int $nb_connections;
	public int $nb_connections_limit;

	public int $addition_date;
	public int $completion_date;
	public int $last_seen;
	public int $time_elapsed;
	public int $seeding_time;
	public int $eta;
	public int $reannounce;

	public string $save_path;
	public string $download_path;
}
