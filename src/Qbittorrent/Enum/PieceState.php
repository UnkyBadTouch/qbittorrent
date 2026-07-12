<?php

namespace Blackout\Qbittorrent\Enum;

enum PieceState: int
{
	use \Blackout\Qbittorrent\Trait\HasLabel;

	case NOT_DOWNLOADED = 0;
	case DOWNLOADING = 1;
	case DOWNLOADED = 2;
}
