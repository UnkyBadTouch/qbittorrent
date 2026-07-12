<?php

namespace Blackout\Qbittorrent\DTO\Torrent;

use Blackout\Qbittorrent\DTO\Base;

final class Peer extends Base
{
	public string $ip;
	public int $port;
	public string $client;
	public string $peer_id_client;
	public string $country;
	public string $country_code;

	public float $progress;
	public int $dl_speed;
	public int $up_speed;
	public int $downloaded;
	public int $uploaded;

	public string $connection;
	public string $flags;
	public string $flags_desc;
	public float $relevance;
	public string $files;
	public float $contribution;
	public string $host_name;
	public string $i2p_dest;
}
