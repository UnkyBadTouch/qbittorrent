<?php

namespace Blackout\Qbittorrent\DTO\Search;

use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\Enum\SearchStatus as SearchStatusEnum;

final class Status extends Base
{
	public int $id;
	public SearchStatusEnum $status;
	public int $total;

	public function stop(): void
	{
		$this->qbittorrent->stopSearch($this->id);
		$this->status = SearchStatusEnum::STOPPED;
	}

	public function delete(): void
	{
		$this->qbittorrent->deleteSearch($this->id);
	}

	public function results(?int $limit = null, ?int $offset = null): array
	{
		return $this->qbittorrent->getSearchResults($this->id, $limit, $offset);
	}
}
