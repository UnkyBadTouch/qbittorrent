<?php

namespace Blackout\Qbittorrent\Enum;

enum TrackerStatus: int
{
	use \Blackout\Qbittorrent\Trait\HasLabel;

	case DISABLED = 0;
	case NOT_CONTACTED = 1;
	case WORKING = 2;
	case NOT_WORKING = 4;
	case TRACKER_ERROR = 5;
	case UNREACHABLE = 6;

	public function isHealthy(): bool
	{
		return $this === self::WORKING;
	}

	public function isActive(): bool
	{
		return $this !== self::DISABLED;
	}
}
