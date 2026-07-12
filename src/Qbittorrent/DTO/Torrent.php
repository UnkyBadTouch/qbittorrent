<?php

namespace Blackout\Qbittorrent\DTO;

use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\DTO\Torrent\File;
use Blackout\Qbittorrent\DTO\Torrent\Peer;
use Blackout\Qbittorrent\DTO\Torrent\Piece;
use Blackout\Qbittorrent\DTO\Torrent\Tracker;
use Blackout\Qbittorrent\DTO\Torrent\WebSeed;
use Blackout\Qbittorrent\Enum\TorrentState;
use Blackout\Qbittorrent\Enum\ShareLimitAction;
use Blackout\Qbittorrent\Enum\ShareLimitsMode;
use Blackout\Qbittorrent\Attribute\Relation;

use Blackout\Helper;

final class Torrent extends Base
{
	// identity
	public string $hash;
	public string $infohash_v1;
	public string $infohash_v2;
	public string $name;
	public string $magnet_uri;
	public string $comment;

	// torrent metadata
	public ?bool $private;
	public bool $has_metadata;
	public string $created_by;
	public int $creation_date;
	public int $pieces_num;
	public int $piece_size;
	public int $pieces_have;
	public int $total_wasted;

	// state
	public TorrentState $state;
	public float $progress;
	public int $priority;
	public float $availability;

	// sizes
	public int $size;
	public int $total_size;
	public int $completed;
	public int $downloaded;
	public int $uploaded;

	// speed
	public int $dlspeed;
	public int $upspeed;

	// ratios
	public float $ratio;
	public float $ratio_limit;
	public float $max_ratio;
	public float $popularity;
	public ShareLimitAction $share_limit_action;
	public ShareLimitsMode $share_limits_mode;

	// peers
	public int $num_seeds;
	public int $num_leechs;
	public int $num_complete;
	public int $num_incomplete;
	public int $connections_count;
	public int $connections_limit;

	// timing
	public int $eta;
	public int $added_on;
	public int $completion_on;
	public int $last_activity;
	public int $seeding_time;
	public int $seeding_time_limit;
	public int $max_seeding_time;
	public int $inactive_seeding_time_limit;
	public int $max_inactive_seeding_time;
	public int $time_active;
	public int $reannounce;

	// flags
	public bool $seq_dl;
	public bool $f_l_piece_prio;
	public bool $force_start;
	public bool $auto_tmm;
	public bool $super_seeding;

	// categorization
	public string $category;
	public string $tags;
	public string $save_path;
	public string $content_path;
	public string $download_path;
	public string $root_path;

	// advanced stats
	public int $downloaded_session;
	public int $uploaded_session;
	public int $amount_left;
	public int $seen_complete;

	// limits
	public int $dl_limit;
	public int $up_limit;

	// tracker
	public string $tracker;
	public int $trackers_count;

	// files / trackers / peers
	/** @var File[] */
	#[Relation('getTorrentFiles', File::class)]
	public array $files { get => $this->relation(__PROPERTY__); }

	/** @var Tracker[] */
	#[Relation('getTorrentTrackers', Tracker::class)]
	public array $trackers { get => $this->relation(__PROPERTY__); }

	/** @var Peer[] */
	#[Relation('getTorrentPeers', Peer::class)]
	public array $peers { get => $this->relation(__PROPERTY__); }

	/** @var Piece[] */
	#[Relation('getTorrentPiecesStates', Piece::class)]
	public array $pieces { get => $this->relation(__PROPERTY__); }

	/** @var WebSeed[] */
	#[Relation('getTorrentWebSeeds', WebSeed::class)]
	public array $webseeds { get => $this->relation(__PROPERTY__); }

	public float $progress_percent { get => round($this->progress * 100, 2); }
	public bool $is_complete { get => $this->progress >= 1; }
	public string $size_human { get => Helper::filesize($this->size); }

	/** @var File[] */
	public array $downloadable_files { get => array_values(array_filter($this->files, fn($f) => !$f->priority->isDoNotDownload())); }

	public function addTags(string|array $tags): void
	{
		$tags = (array)$tags;
		$this->qbittorrent->addTorrentTags($this->hash, $tags);
		$this->tags = implode(',', array_unique([...$this->tagList(), ...$tags]));
	}

	public function removeTags(string|array $tags): void
	{
		$tags = (array)$tags;
		$this->qbittorrent->removeTorrentTags($this->hash, $tags);
		$this->tags = implode(',', array_diff($this->tagList(), $tags));
	}

	private function tagList(): array
	{
		return array_filter(array_map('trim', explode(',', $this->tags)));
	}

	public function recheck(): void
	{
		$this->qbittorrent->recheck($this->hash);
	}

	public function stop(): void
	{
		$this->qbittorrent->stop($this->hash);
	}

	public function start(): void
	{
		$this->qbittorrent->start($this->hash);
	}

	public function reannounce(): void
	{
		$this->qbittorrent->reannounce($this->hash);
	}

	public function delete(bool $deleteFiles = false): void
	{
		$this->qbittorrent->delete($this->hash, $deleteFiles);
	}

	public function setCategory(string $category): void
	{
		$this->qbittorrent->setTorrentCategory($this->hash, $category);
		$this->category = $category;
	}

	public function downloadFile(string|File $file): string
	{
		return $this->qbittorrent->downloadFile($this->hash, $file);
	}

	public function export(): string
	{
		return $this->qbittorrent->exportTorrent($this->hash);
	}

	public function addTrackers(string|array $urls): void
	{
		$this->qbittorrent->addTrackers($this->hash, $urls);
		unset($this->_relations['trackers']);
	}

	public function editTracker(string $origUrl, string $newUrl): void
	{
		$this->qbittorrent->editTracker($this->hash, $origUrl, $newUrl);
		unset($this->_relations['trackers']);
	}

	public function removeTrackers(string|array $urls): void
	{
		$this->qbittorrent->removeTrackers($this->hash, $urls);
		unset($this->_relations['trackers']);
	}

	public function addPeers(string|array $peers): void
	{
		$this->qbittorrent->addPeers($this->hash, $peers);
		unset($this->_relations['peers']);
	}

	public function increasePrio(): void
	{
		$this->qbittorrent->increasePrio($this->hash);
	}

	public function decreasePrio(): void
	{
		$this->qbittorrent->decreasePrio($this->hash);
	}

	public function topPrio(): void
	{
		$this->qbittorrent->topPrio($this->hash);
	}

	public function bottomPrio(): void
	{
		$this->qbittorrent->bottomPrio($this->hash);
	}

	public function setFilePrio(string|array $ids, int $priority): void
	{
		$this->qbittorrent->setFilePrio($this->hash, $ids, $priority);
		unset($this->_relations['files']);
	}

	public function getDownloadLimit(): int
	{
		return $this->qbittorrent->getDownloadLimit($this->hash)[$this->hash];
	}

	public function setDownloadLimit(int $limit): void
	{
		$this->qbittorrent->setDownloadLimit($this->hash, $limit);
		$this->dl_limit = $limit;
	}

	public function getUploadLimit(): int
	{
		return $this->qbittorrent->getUploadLimit($this->hash)[$this->hash];
	}

	public function setUploadLimit(int $limit): void
	{
		$this->qbittorrent->setUploadLimit($this->hash, $limit);
		$this->up_limit = $limit;
	}

	public function setShareLimits(float $ratioLimit, int $seedingTimeLimit, int $inactiveSeedingTimeLimit, string $shareLimitAction = 'Default', string $shareLimitsMode = 'Default'): void
	{
		$this->qbittorrent->setShareLimits($this->hash, $ratioLimit, $seedingTimeLimit, $inactiveSeedingTimeLimit, $shareLimitAction, $shareLimitsMode);
		$this->ratio_limit = $ratioLimit;
		$this->seeding_time_limit = $seedingTimeLimit;
		$this->inactive_seeding_time_limit = $inactiveSeedingTimeLimit;
		$this->share_limit_action = ShareLimitAction::from($shareLimitAction);
		$this->share_limits_mode = ShareLimitsMode::from($shareLimitsMode);
	}

	public function setLocation(string $location): void
	{
		$this->qbittorrent->setLocation($this->hash, $location);
		$this->save_path = $location;
	}

	public function rename(string $name): void
	{
		$this->qbittorrent->renameTorrent($this->hash, $name);
		$this->name = $name;
	}

	public function renameFile(string $oldPath, string $newPath): void
	{
		$this->qbittorrent->renameFile($this->hash, $oldPath, $newPath);
		unset($this->_relations['files']);
	}

	public function renameFolder(string $oldPath, string $newPath): void
	{
		$this->qbittorrent->renameFolder($this->hash, $oldPath, $newPath);
		unset($this->_relations['files']);
	}

	public function setAutoManagement(bool $enable): void
	{
		$this->qbittorrent->setAutoManagement($this->hash, $enable);
		$this->auto_tmm = $enable;
	}

	public function toggleSequentialDownload(): void
	{
		$this->qbittorrent->toggleSequentialDownload($this->hash);
		$this->seq_dl = !$this->seq_dl;
	}

	public function toggleFirstLastPiecePrio(): void
	{
		$this->qbittorrent->toggleFirstLastPiecePrio($this->hash);
		$this->f_l_piece_prio = !$this->f_l_piece_prio;
	}

	public function setForceStart(bool $value): void
	{
		$this->qbittorrent->setForceStart($this->hash, $value);
		$this->force_start = $value;
	}

	public function setSuperSeeding(bool $value): void
	{
		$this->qbittorrent->setSuperSeeding($this->hash, $value);
		$this->super_seeding = $value;
	}

	public function addWebSeeds(string|array $urls): void
	{
		$this->qbittorrent->addWebSeeds($this->hash, $urls);
		unset($this->_relations['webseeds']);
	}

	public function editWebSeed(string $origUrl, string $newUrl): void
	{
		$this->qbittorrent->editWebSeed($this->hash, $origUrl, $newUrl);
		unset($this->_relations['webseeds']);
	}

	public function removeWebSeeds(string|array $urls): void
	{
		$this->qbittorrent->removeWebSeeds($this->hash, $urls);
		unset($this->_relations['webseeds']);
	}

	public function setComment(string $comment): void
	{
		$this->qbittorrent->setTorrentComment($this->hash, $comment);
		$this->comment = $comment;
	}

	public function setSavePath(string $path): void
	{
		$this->qbittorrent->setTorrentSavePath($this->hash, $path);
		$this->save_path = $path;
	}

	public function setDownloadPath(string $path): void
	{
		$this->qbittorrent->setTorrentDownloadPath($this->hash, $path);
		$this->download_path = $path;
	}

	public function setTags(string|array $tags): void
	{
		$this->qbittorrent->setTorrentTags($this->hash, $tags);
		$this->tags = implode(',', (array)$tags);
	}

	public function getSslParameters(): array
	{
		return $this->qbittorrent->getTorrentSslParameters($this->hash);
	}

	public function setSslParameters(string $certificate, string $privateKey, string $dhParams): void
	{
		$this->qbittorrent->setTorrentSslParameters($this->hash, $certificate, $privateKey, $dhParams);
	}
}
