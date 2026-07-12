<?php

namespace Blackout\Qbittorrent\DTO\Log;

use Blackout\Qbittorrent\DTO\Base;

final class Peer extends Base
{
	public int $id;
	public string $ip;
	public int $timestamp;
	public bool $blocked;
	public string $reason;
}
