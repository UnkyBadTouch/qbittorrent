<?php

namespace Blackout\Qbittorrent\DTO\Log;

use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\Enum\LogMessageType;

final class Message extends Base
{
	public int $id;
	public string $message;
	public int $timestamp;
	public LogMessageType $type;
}
