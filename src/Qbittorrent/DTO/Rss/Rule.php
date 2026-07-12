<?php

declare(strict_types=1);

namespace Blackout\Qbittorrent\DTO\Rss;

use Blackout\Qbittorrent\DTO\Base;

final class Rule extends Base
{
	// not an API field — injected by Client::getRssRules() from the rule's
	// key in the /rss/rules response object, since the API only exposes a
	// rule's name as that key, never as a field on the rule itself
	public string $name;

	public bool $enabled;
	public int $priority;
	public bool $useRegex;
	public string $mustContain;
	public string $mustNotContain;
	public string $episodeFilter;
	public array $affectedFeeds;
	public string $lastMatch;
	public int $ignoreDays;
	public bool $smartFilter;
	public array $previouslyMatchedEpisodes;

	// deprecated fields, still emitted by qBittorrent alongside torrentParams —
	// but ignored on write: fromJsonObject() (rss_autodownloadrule.cpp) uses
	// torrentParams exclusively whenever the posted JSON contains that key at
	// all, which ours always does. Each setter here mirrors into torrentParams
	// under its real key so setRssRule()/save() actually takes effect.
	public ?bool $addPaused {
		set {
			$this->addPaused = $value;
			if ($value !== null) $this->torrentParams['stopped'] = $value;
		}
	}
	public ?string $torrentContentLayout {
		set {
			$this->torrentContentLayout = $value;
			if ($value !== null) $this->torrentParams['content_layout'] = $value;
		}
	}
	public string $savePath {
		set {
			$this->savePath = $value;
			$this->torrentParams['save_path'] = $value;
		}
	}
	public string $assignedCategory {
		set {
			$this->assignedCategory = $value;
			$this->torrentParams['category'] = $value;
		}
	}

	public array $torrentParams;

	public function save(): void
	{
		$ruleDef = $this->jsonSerialize();
		unset($ruleDef['name']);
		$this->qbittorrent->setRssRule($this->name, $ruleDef);
	}

	public function rename(string $newName): void
	{
		$this->qbittorrent->renameRssRule($this->name, $newName);
		$this->name = $newName;
	}

	public function clone(string $cloneName): void
	{
		$this->qbittorrent->cloneRssRule($this->name, $cloneName);
	}

	public function matchingArticles(): array
	{
		return $this->qbittorrent->getMatchingArticles($this->name);
	}

	public function delete(): void
	{
		$this->qbittorrent->deleteRssRule($this->name);
	}
}
