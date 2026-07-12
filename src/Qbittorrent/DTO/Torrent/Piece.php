<?php

namespace Blackout\Qbittorrent\DTO\Torrent;

use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\Enum\PieceState;

final class Piece extends Base
{
	public int $index;
	public PieceState $state;

	public bool $is_downloaded { get => $this->state === PieceState::DOWNLOADED; }
	public bool $is_downloading { get => $this->state === PieceState::DOWNLOADING; }
}
