<?php

declare(strict_types=1);

namespace Blackout\Qbittorrent\Enum;

enum FilePriority: int
{
	use \Blackout\Qbittorrent\Trait\HasLabel;
	
	case DO_NOT_DOWNLOAD = 0;
	case NORMAL = 1;
	case HIGH = 6;
	case MAXIMUM = 7;

	public function isDoNotDownload(): bool
	{
		return $this === self::DO_NOT_DOWNLOAD;
	}

public function isNormal(): bool
	{
		return $this === self::NORMAL;
	}

	public function isHigh(): bool
	{
		return $this === self::HIGH || $this === self::MAXIMUM;
	}
}
