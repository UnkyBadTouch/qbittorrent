<?php

namespace Blackout\Qbittorrent\Enum;

enum ShareLimitsMode: string
{
	use \Blackout\Qbittorrent\Trait\HasLabel;

	case DEFAULT = 'Default';
	case MATCH_ANY = 'MatchAny';
	case MATCH_ALL = 'MatchAll';
}
