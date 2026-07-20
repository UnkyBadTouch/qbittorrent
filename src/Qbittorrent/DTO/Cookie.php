<?php

namespace Blackout\Qbittorrent\DTO;

final class Cookie extends Base
{
	public string $name;
	public string $domain;
	public string $path;
	public string $value;

	// seconds since epoch; accepts a date string or DateTimeInterface (e.g. Carbon)
	public int|string|\DateTimeInterface $expirationDate {
		set => match (true)
		{
			$value instanceof \DateTimeInterface => $value->getTimestamp(),
			is_string($value) => (new \DateTimeImmutable($value))->getTimestamp(),
			default => $value,
		};
	}
}
