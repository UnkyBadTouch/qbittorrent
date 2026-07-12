<?php

namespace Blackout\Qbittorrent\DTO;

final class Transfer extends Base
{
	public string $connection_status;
	public int $dht_nodes;
	public int $dl_info_data;
	public int $dl_info_speed;
	public int $dl_rate_limit;
	public string $last_external_address_v4;
	public string $last_external_address_v6;
	public int $up_info_data;
	public int $up_info_speed;
	public int $up_rate_limit;

	public function speedLimitsMode(): bool
	{
		return $this->qbittorrent->getSpeedLimitsMode();
	}

	public function toggleSpeedLimitsMode(): void
	{
		$this->qbittorrent->toggleSpeedLimitsMode();
	}

	public function setDownloadLimit(int $limit): void
	{
		$this->qbittorrent->setGlobalDownloadLimit($limit);
		$this->dl_rate_limit = $limit;
	}

	public function setUploadLimit(int $limit): void
	{
		$this->qbittorrent->setGlobalUploadLimit($limit);
		$this->up_rate_limit = $limit;
	}

	public function banPeers(string|array $peers): void
	{
		$this->qbittorrent->banPeers($peers);
	}
}
