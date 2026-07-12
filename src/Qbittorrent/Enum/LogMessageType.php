<?php

namespace Blackout\Qbittorrent\Enum;

enum LogMessageType: int
{
	use \Blackout\Qbittorrent\Trait\HasLabel;

	case NORMAL = 1;
	case INFO = 2;
	case WARNING = 4;
	case CRITICAL = 8;
}
