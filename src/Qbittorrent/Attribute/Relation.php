<?php

declare(strict_types=1);

namespace Blackout\Qbittorrent\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Relation
{
	public function __construct(
		public string $method,
		public ?string $class = null,
	) {
	}
}
