<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Rss\Feed;
use Blackout\Qbittorrent\DTO\Rss\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rule::class)]
final class RssRuleTest extends TestCase
{
	public function testHydratesTheFieldsTheApiActuallySends(): void
	{
		$rule = new Rule(
            [
			'name'                      => 'Linux isos',
			'enabled'                   => true,
			'priority'                  => 0,
			'useRegex'                  => false,
			'mustContain'               => 'ubuntu',
			'mustNotContain'            => 'alpha',
			'episodeFilter'             => 's\\d+e\\d+',
			'affectedFeeds'             => ['https://a.test/rss'],
			'lastMatch'                 => '',
			'ignoreDays'                => 0,
			'smartFilter'               => false,
			'previouslyMatchedEpisodes' => [],
			'torrentParams'             => ['category' => 'linux', 'save_path' => '/data'],
            ]
		);

		$this->assertSame('Linux isos', $rule->name);
		$this->assertTrue($rule->enabled);
		$this->assertSame('ubuntu', $rule->mustContain);
		$this->assertSame('alpha', $rule->mustNotContain);
		$this->assertSame(['https://a.test/rss'], $rule->affectedFeeds);
		$this->assertSame('/data', $rule->torrentParams['save_path']);
	}

	/**
	 * The deprecated fields are write-dead: fromJsonObject() reads
	 * torrentParams exclusively, so each setter mirrors its value into the
	 * matching torrentParams key under its real name.
	 */
	public function testAddPausedMirrorsIntoTorrentParamsAsStopped(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => []]);

		$rule->addPaused = true;

		$this->assertTrue($rule->addPaused);
		$this->assertTrue($rule->torrentParams['stopped']);
	}

	public function testAddPausedNullLeavesTorrentParamsUntouched(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => ['category' => 'linux']]);

		$rule->addPaused = null;

		$this->assertNull($rule->addPaused);
		$this->assertArrayNotHasKey('stopped', $rule->torrentParams);
	}

	public function testTorrentContentLayoutMirrorsAsContentLayout(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => []]);

		$rule->torrentContentLayout = 'Subfolder';

		$this->assertSame('Subfolder', $rule->torrentContentLayout);
		$this->assertSame('Subfolder', $rule->torrentParams['content_layout']);
	}

	public function testSavePathMirrorsAsSavePath(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => []]);

		$rule->savePath = '/downloads/linux';

		$this->assertSame('/downloads/linux', $rule->savePath);
		$this->assertSame('/downloads/linux', $rule->torrentParams['save_path']);
	}

	public function testAssignedCategoryMirrorsAsCategory(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => []]);

		$rule->assignedCategory = 'linux';

		$this->assertSame('linux', $rule->assignedCategory);
		$this->assertSame('linux', $rule->torrentParams['category']);
	}

	/**
	 * Mirroring must survive the dynamic property write an HNR loop would
	 * use, not just a literal assignment.
	 */
	public function testMirroringFiresOnDynamicPropertyWrites(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => []]);

		$rule->{'assignedCategory'} = 'movies';
		$rule->{'savePath'} = '/mnt/media';

		$this->assertSame('movies', $rule->torrentParams['category']);
		$this->assertSame('/mnt/media', $rule->torrentParams['save_path']);
	}

	/**
	 * Documented quirk, not desired behaviour.
	 *
	 * In Rule.php the four mirrored properties are declared *before*
	 * $torrentParams, and Base::hydrate() walks properties in declaration
	 * order. So assigning savePath during hydration does fire its hook and
	 * write into an auto-vivified $torrentParams, and then hydration of
	 * $torrentParams itself immediately overwrites that array with the
	 * payload's value.
	 *
	 * The net effect is benign for a real response — the API already sends
	 * the authoritative save_path inside torrentParams, and that is the
	 * value save() posts. The local $savePath property is still populated,
	 * so anything that reads it sees the right thing; only the mirror is
	 * lost. Asserted here so a reordering of the declarations is a visible
	 * test change rather than a silent behaviour shift.
	 */
	public function testHydrationOverwritesTheMirrorWithTheApisOwnTorrentParams(): void
	{
		$rule = new Rule([
			'name'          => 'r',
			'savePath'      => '/from-api',
			'torrentParams' => ['category' => 'linux'],
		]);

		$this->assertSame('/from-api', $rule->savePath, 'The property itself is hydrated.');
		$this->assertArrayNotHasKey(
			'save_path',
			$rule->torrentParams,
			'The hook does fire during hydration, but the later $torrentParams '
			. 'assignment wins because it is declared second.',
		);
		$this->assertSame('linux', $rule->torrentParams['category']);
	}

	public function testMirroringSurvivesWhenHydrationRunsWithoutATorrentParamsKey(): void
	{
		// With no torrentParams key in the payload there is nothing to
		// overwrite, so the mirror from hydration stands.
		$rule = new Rule(['name' => 'r', 'savePath' => '/from-api']);

		$this->assertSame('/from-api', $rule->torrentParams['save_path']);
	}

	/**
	 * There is no per-field RSS rule endpoint, so save() posts the whole
	 * object — minus the synthesized name, which the API keys the rule by and
	 * would reject as a field.
	 */
	public function testSavePostsTheRuleWithoutItsName(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('setRssRule')
			->with(
				'Linux isos',
				$this->callback(function (array $def): bool {
					$this->assertArrayNotHasKey('name', $def);
					$this->assertTrue($def['enabled']);
					$this->assertSame('/data', $def['torrentParams']['save_path']);

					return true;
				}),
			);

		$rule = new Rule(
            [
			'name'          => 'Linux isos',
			'enabled'       => true,
			'torrentParams' => ['save_path' => '/data'],
            ],
            $client,
		);

		$rule->save();
	}

	public function testRenameForwardsAndUpdatesTheLocalName(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('renameRssRule')
			->with('old', 'new');

		$rule = new Rule(['name' => 'old'], $client);

		$rule->rename('new');

		$this->assertSame('new', $rule->name);
	}

	public function testCloneForwardsBothNames(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('cloneRssRule')
			->with('source', 'copy');

		(new Rule(['name' => 'source'], $client))->clone('copy');
	}

	public function testMatchingArticlesReturnsTheRawList(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getMatchingArticles')
			->with('rule')
			->willReturn([['title' => 'Post']]);

		$articles = (new Rule(['name' => 'rule'], $client))->matchingArticles();

		$this->assertSame('Post', $articles[0]['title']);
	}

	public function testDeleteForwardsTheName(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('deleteRssRule')
			->with('rule');

		(new Rule(['name' => 'rule'], $client))->delete();
	}

	public function testJsonSerializeCarriesBothThePropertyAndTheMirror(): void
	{
		$rule = new Rule(['name' => 'r', 'torrentParams' => []]);

		// Set after construction, so no later hydration overwrites the mirror.
		$rule->savePath = '/data';

		$json = $rule->jsonSerialize();

		$this->assertSame('/data', $json['savePath']);
		$this->assertSame('/data', $json['torrentParams']['save_path']);
	}
}
