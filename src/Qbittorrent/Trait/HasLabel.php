<?php

namespace Blackout\Qbittorrent\Trait;

Trait HasLabel
{
	public function label(): string
	{
		return ucwords(strtolower(str_replace('_', ' ', $this->name)));
	}
}
