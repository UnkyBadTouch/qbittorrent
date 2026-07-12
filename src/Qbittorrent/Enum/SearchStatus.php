<?php

namespace Blackout\Qbittorrent\Enum;

enum SearchStatus: string
{
	use \Blackout\Qbittorrent\Trait\HasLabel;

	case RUNNING = 'Running';
	case STOPPED = 'Stopped';
}
