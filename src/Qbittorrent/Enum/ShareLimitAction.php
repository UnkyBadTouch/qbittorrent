<?php

namespace Blackout\Qbittorrent\Enum;

enum ShareLimitAction: string
{
	use \Blackout\Qbittorrent\Trait\HasLabel;

	case DEFAULT = 'Default';
	case STOP = 'Stop';
	case REMOVE = 'Remove';
	case REMOVE_WITH_CONTENT = 'RemoveWithContent';
	case ENABLE_SUPER_SEEDING = 'EnableSuperSeeding';
}
