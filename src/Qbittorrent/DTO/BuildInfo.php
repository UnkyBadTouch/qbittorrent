<?php

namespace Blackout\Qbittorrent\DTO;

final class BuildInfo extends Base
{
	public int $bitness;
	public string $boost;
	public string $libtorrent;
	public string $openssl;
	public string $platform;
	public string $qt;
	public string $zlib;
}
