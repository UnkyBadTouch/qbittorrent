<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Torrent\Peer;
use Blackout\Qbittorrent\DTO\Torrent\Piece;
use Blackout\Qbittorrent\DTO\Torrent\Tracker;
use Blackout\Qbittorrent\Enum\PieceState;
use Blackout\Qbittorrent\Enum\SearchStatus;
use Blackout\Qbittorrent\Enum\TorrentState;
use Blackout\Qbittorrent\Enum\TrackerStatus;
use Blackout\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The Client methods that do more than forward a verb and a path: response
 * unwrapping, envelope reshaping, and value coercion.
 */
#[CoversClass(Client::class)]
final class ClientTest extends ClientTestCase
{
	public function testVersionEndpointsReturnRawStrings(): void
	{
		$this->queueText('v4.6.5');
		$this->assertSame('v4.6.5', $this->client->getVersion());

		$this->queueText('2.9.3');
		$this->assertSame('2.9.3', $this->client->getWebApiVersion());
	}

	public function testSpeedLimitsModeParsesTheOneFlagIntoABool(): void
	{
		$this->queueText('1');
		$this->assertTrue($this->client->getSpeedLimitsMode());

		$this->queueText('0');
		$this->assertFalse($this->client->getSpeedLimitsMode());
	}

	public function testSpeedLimitsModeIsFalseForAnythingOtherThanOne(): void
	{
		$this->queueText('true');
		$this->assertFalse($this->client->getSpeedLimitsMode());
	}

	/**
	 * These endpoints answer with a bare number as text, not JSON, so the int
	 * cast in Client is what turns them into usable values.
	 */
	public function testNumericEndpointsCastTheirTextResponses(): void
	{
		$this->queueText('123456789');
		$this->assertSame(123456789, $this->client->getFreeSpaceAtPath('/data'));

		$this->queueText('1048576');
		$this->assertSame(1048576, $this->client->getGlobalDownloadLimit());

		$this->queueText('42');
		$this->assertSame(42, $this->client->getTorrentsCount());
	}

	public function testGetFreeSpaceAtPathSendsThePathAsAQueryParam(): void
	{
		$this->queueText('1');

		$this->client->getFreeSpaceAtPath('/mnt/data');

		$this->assertRequest('GET', '/api/v2/app/getFreeSpaceAtPath');
		$this->assertSame('/mnt/data', $this->lastQueryParams()['path']);
	}

	/**
	 * /torrents/categories answers {name: {...}}, and the DTO layer must keep
	 * those keys so callers can index by category name.
	 */
	public function testGetCategoriesReturnsKeyedDtos(): void
	{
		$this->queueJson(
            [
			'linux'   => ['name' => 'linux', 'savePath' => '/l'],
			'windows' => ['name' => 'windows', 'savePath' => '/w'],
            ]
		);

		$categories = $this->client->getCategories();

		$this->assertSame(['linux', 'windows'], array_keys($categories));
		$this->assertSame('/l', $categories['linux']->savePath);
	}

	public function testGetTorrentsHydratesEnumFields(): void
	{
		$this->queueJson(
            [
			['hash' => 'abc', 'name' => 'a', 'state' => 'uploading'],
			['hash' => 'def', 'name' => 'b', 'state' => 'stalledUP'],
            ]
		);

		$torrents = $this->client->getTorrents();

		$this->assertCount(2, $torrents);
		$this->assertSame(TorrentState::UPLOADING, $torrents[0]->state);
		$this->assertSame(TorrentState::STALLED_UP, $torrents[1]->state);
	}

	public function testGetTorrentsByCategoryInjectsTheCategoryFilter(): void
	{
		$this->queueJson([]);

		$this->client->getTorrentsByCategory('linux', ['tag' => 'iso']);

		$this->assertRequest('GET', '/api/v2/torrents/info');
		$this->assertSame(
			['tag' => 'iso', 'category' => 'linux'],
			$this->lastQueryParams(),
		);
	}

	public function testGetTorrentsByHashJoinsMultipleHashesWithAPipe(): void
	{
		$this->queueJson([]);

		$this->client->getTorrentsByHash(['aaa', 'bbb']);

		$this->assertSame('aaa|bbb', $this->lastQueryParams()['hashes']);
	}

	public function testGetTorrentsByHashAcceptsASingleBareHash(): void
	{
		$this->queueJson([]);

		$this->client->getTorrentsByHash('aaa');

		$this->assertSame('aaa', $this->lastQueryParams()['hashes']);
	}

	public function testGetTorrentTrackersHydratesTrackerStatusEnums(): void
	{
		$this->queueJson(
            [
			['url' => 'https://a.test/', 'status' => 2, 'tier' => 0],
			['url' => 'https://b.test/', 'status' => 4, 'tier' => 1],
            ]
		);

		$trackers = $this->client->getTorrentTrackers('abc');

		$this->assertContainsOnlyInstancesOf(Tracker::class, $trackers);
		$this->assertSame(TrackerStatus::WORKING, $trackers[0]->status);
		$this->assertSame(TrackerStatus::NOT_WORKING, $trackers[1]->status);
	}

	/**
	 * /sync/torrentPeers wraps its rows in a {"peers": {...}} envelope keyed by
	 * IP, which is not a shape toDto() can unwrap on its own.
	 */
	public function testGetTorrentPeersUnwrapsTheEnvelope(): void
	{
		$this->queueJson(
            [
			'peers' => [
				'1.2.3.4:6881' => ['ip' => '1.2.3.4', 'port' => 6881, 'client' => 'qBt 4.6.0'],
				'5.6.7.8:51413' => ['ip' => '5.6.7.8', 'port' => 51413, 'client' => 'Transmission'],
			],
            ]
		);

		$peers = $this->client->getTorrentPeers('abc');

		$this->assertContainsOnlyInstancesOf(Peer::class, $peers);
		$this->assertCount(2, $peers);
		$this->assertSame('1.2.3.4', $peers[0]->ip);
		$this->assertSame('Transmission', $peers[1]->client);
	}

	public function testGetTorrentPeersOnAnEmptyEnvelope(): void
	{
		$this->queueJson(['peers' => []]);

		$this->assertSame([], $this->client->getTorrentPeers('abc'));
	}

	public function testGetTorrentPeersToleratesAMissingPeersKey(): void
	{
		$this->queueJson([]);

		$this->assertSame([], $this->client->getTorrentPeers('abc'));
	}

	/**
	 * /torrents/pieceStates answers a flat [state, state, ...] list, which
	 * carries no index — the DTO needs one, so states are zipped with their
	 * positions.
	 */
	public function testGetTorrentPiecesStatesZipsTheFlatListWithIndices(): void
	{
		$this->queueJson([0, 2, 1, 2]);

		$pieces = $this->client->getTorrentPiecesStates('abc');

		$this->assertContainsOnlyInstancesOf(Piece::class, $pieces);
		$this->assertCount(4, $pieces);
		$this->assertSame([0, 1, 2, 3], array_map(fn (Piece $p) => $p->index, $pieces));
		$this->assertSame(
			[
				PieceState::NOT_DOWNLOADED,
				PieceState::DOWNLOADED,
				PieceState::DOWNLOADING,
				PieceState::DOWNLOADED,
			],
			array_map(fn (Piece $p) => $p->state, $pieces),
		);
	}

	public function testGetTorrentPiecesStatesOnAnEmptyList(): void
	{
		$this->queueJson([]);

		$this->assertSame([], $this->client->getTorrentPiecesStates('abc'));
	}

	/**
	 * The zip uses each entry's JSON key as the piece index, so a response
	 * keyed by real piece numbers keeps them rather than being renumbered
	 * from zero.
	 */
	public function testGetTorrentPiecesStatesUsesTheJsonKeysAsIndices(): void
	{
		$this->queueJson([5 => 2, 9 => 0]);

		$pieces = $this->client->getTorrentPiecesStates('abc');

		$this->assertSame([5, 9], array_map(fn (Piece $p) => $p->index, $pieces));
		$this->assertSame(PieceState::DOWNLOADED, $pieces[0]->state);
		$this->assertSame(PieceState::NOT_DOWNLOADED, $pieces[1]->state);
	}

	/**
	 * startSearch() answers {"id": N}; callers want the id, not the envelope.
	 */
	public function testStartSearchUnwrapsTheId(): void
	{
		$this->queueJson(['id' => 7]);

		$this->assertSame(7, $this->client->startSearch('ubuntu'));
	}

	public function testStartSearchSendsThePluginListJoinedByAPipe(): void
	{
		$this->queueJson(['id' => 1]);

		$this->client->startSearch('ubuntu', ['pluginA', 'pluginB']);

		$params = $this->assertRequest('POST', '/api/v2/search/start');
		$this->assertSame('ubuntu', $params['pattern']);
		$this->assertSame('pluginA|pluginB', $params['plugins']);
		$this->assertSame('all', $params['category']);
	}

	/**
	 * getSearchResults() returns {results, status, total}, where status is a
	 * string the enum has to be built from.
	 */
	public function testGetSearchResultsHydratesStatusAndResults(): void
	{
		$this->queueJson(
            [
			'results' => [
				['fileName' => 'ubuntu.iso', 'fileUrl' => 'https://a.test/ubuntu.iso', 'nbSeeders' => 5],
			],
			'status'   => 'Running',
			'total'    => 1,
            ]
		);

		$result = $this->client->getSearchResults(7);

		$this->assertSame(SearchStatus::RUNNING, $result['status']);
		$this->assertSame(1, $result['total']);
		$this->assertCount(1, $result['results']);
		$this->assertSame('ubuntu.iso', $result['results'][0]->fileName);
		$this->assertSame(5, $result['results'][0]->nbSeeders);
	}

	public function testGetSearchResultsOmitsNullLimitAndOffset(): void
	{
		$this->queueJson(['results' => [], 'status' => 'Stopped', 'total' => 0]);

		$this->client->getSearchResults(7);

		$this->assertRequest('GET', '/api/v2/search/results');
		$this->assertSame(['id' => '7'], $this->lastQueryParams());
	}

	public function testGetSearchResultsForwardsLimitAndOffsetWhenGiven(): void
	{
		$this->queueJson(['results' => [], 'status' => 'Stopped', 'total' => 0]);

		$this->client->getSearchResults(7, 10, 20);

		$this->assertSame(
			['id' => '7', 'limit' => '10', 'offset' => '20'],
			$this->lastQueryParams(),
		);
	}

	/**
	 * /rss/rules exposes each rule's name only as the key of the response
	 * object, never as a field, so hydration has to inject it.
	 */
	public function testGetRssRulesInjectsTheNameFromTheResponseKey(): void
	{
		$this->queueJson(
            [
			'Linux isos' => ['enabled' => true, 'mustContain' => 'ubuntu'],
			'TV'         => ['enabled' => false, 'mustContain' => '1080p'],
            ]
		);

		$rules = $this->client->getRssRules();

		$this->assertSame(['Linux isos', 'TV'], array_keys($rules));
		$this->assertSame('Linux isos', $rules['Linux isos']->name);
		$this->assertTrue($rules['Linux isos']->enabled);
		$this->assertSame('1080p', $rules['TV']->mustContain);
	}

	public function testGetRssRulesInjectsTheClientIntoEachRule(): void
	{
		$this->queueJson(['rule' => ['enabled' => true]]);

		$rule = $this->client->getRssRules()['rule'];

		$this->assertSame(
			$this->client,
			(new \ReflectionProperty($rule, 'qbittorrent'))->getValue($rule),
		);
	}

	public function testGetMatchingArticlesReturnsTheRawArticleList(): void
	{
		$this->queueJson([['title' => 'Ubuntu 24.04', 'link' => 'https://a.test/1']]);

		$articles = $this->client->getMatchingArticles('rule');

		$this->assertSame('Ubuntu 24.04', $articles[0]['title']);
	}

	public function testGetPreferencesHydratesTheLargeFlatDto(): void
	{
		$this->queueJson(
            [
			'save_path'          => '/downloads',
			'max_ratio'          => 0,
			'web_ui_port'        => 8080,
			'add_trackers'       => '',
            ]
		);

		$prefs = $this->client->getPreferences();

		$this->assertSame('/downloads', $prefs->save_path);
		// max_ratio arrives as an int at its default; the property is a float.
		$this->assertSame(0.0, $prefs->max_ratio);
		$this->assertSame(8080, $prefs->web_ui_port);
	}

	public function testSetPreferencesEncodesThePayloadAsJsonInAFormField(): void
	{
		$this->queueJson([]);

		$this->client->setPreferences(['max_ratio' => 2.5, 'max_ratioing_enabled' => true]);

		$params = $this->assertRequest('POST', '/api/v2/app/setPreferences');
		$this->assertSame(
			['max_ratio' => 2.5, 'max_ratioing_enabled' => true],
			json_decode($params['json'], true),
		);
	}

	public function testLoadClientDataSendsNoKeysParamWhenEmpty(): void
	{
		$this->queueJson([]);

		$this->client->loadClientData();

		$this->assertRequest('GET', '/api/v2/clientdata/load');
		$this->assertSame([], $this->lastQueryParams());
	}

	public function testLoadClientDataEncodesTheKeyListAsJson(): void
    {
		$this->queueJson([]);

		$this->client->loadClientData(['a', 'b']);

		$this->assertSame('["a","b"]', $this->lastQueryParams()['keys']);
	}

	public function testStoreClientDataEncodesThePayloadAsJson(): void
	{
		$this->queueJson([]);

		$this->client->storeClientData(['key' => 'value']);

		$params = $this->assertRequest('POST', '/api/v2/clientdata/store');
		$this->assertSame(['key' => 'value'], json_decode($params['data'], true));
	}

	/**
	 * List-typed params accept a bare value and are cast with (array) before
	 * imploding, so a single item must not gain a trailing separator.
	 *
	 * @param array{0: string, 1: array, 2: string, 3: string} $case
	 */
	public function testListParamsAcceptABareValue(): void
	{
		$cases = [
			['addTorrentTags', ['abc', 'iso'], 'torrents/addTags', 'tags'],
			['removeTorrentTags', ['abc', 'iso'], 'torrents/removeTags', 'tags'],
			['addTrackers', ['abc', 'https://t.test/'], 'torrents/addTrackers', 'urls'],
			['removeTrackers', ['abc', 'https://t.test/'], 'torrents/removeTrackers', 'urls'],
			['addWebSeeds', ['abc', 'https://w.test/'], 'torrents/addWebSeeds', 'urls'],
			['removeWebSeeds', ['abc', 'https://w.test/'], 'torrents/removeWebSeeds', 'urls'],
			['addPeers', ['abc', '1.2.3.4'], 'torrents/addPeers', 'peers'],
			['banPeers', ['1.2.3.4'], 'transfer/banPeers', 'peers'],
			['createTags', ['iso'], 'torrents/createTags', 'tags'],
			['deleteTags', ['iso'], 'torrents/deleteTags', 'tags'],
		];

		foreach ($cases as [$method, $arg, $endpoint, $field]) {
			$this->queueJson([]);

			$this->client->$method(...$arg);

			$params = $this->assertRequest('POST', '/api/v2/' . $endpoint);

			$this->assertSame(end($arg), $params[$field], $method);
		}
	}

	/**
	 * The separator is per-endpoint, not uniform: tags are comma-joined, most
	 * hash and URL lists are pipe-joined, and trackers are newline-joined
	 * because a tracker announce URL cannot contain a comma.
	 */
	public function testListParamsJoinWithTheSeparatorEachEndpointExpects(): void
	{
		$cases = [
			['addTorrentTags', ['abc', ['a', 'b']], 'torrents/addTags', 'tags', 'a,b'],
			['addTrackers', ['abc', ['a', 'b']], 'torrents/addTrackers', 'urls', "a\nb"],
			['addPeers', ['abc', ['a', 'b']], 'torrents/addPeers', 'peers', 'a|b'],
			['banPeers', [['a', 'b']], 'transfer/banPeers', 'peers', 'a|b'],
			['createTags', [['a', 'b']], 'torrents/createTags', 'tags', 'a,b'],
		];

		foreach ($cases as [$method, $args, $endpoint, $field, $expected]) {
			$this->queueJson([]);

			$this->client->$method(...$args);

			$params = $this->assertRequest('POST', '/api/v2/' . $endpoint);

			$this->assertSame($expected, $params[$field], $method);
		}
	}

	/**
	 * Booleans cross the wire as the strings 'true'/'false', not as PHP bools.
	 */
	public function testBooleansAreSentAsStrings(): void
	{
		$cases = [
			['setAutoManagement', ['abc', true], 'setAutoManagement', 'enable', 'true'],
			['setAutoManagement', ['abc', false], 'setAutoManagement', 'enable', 'false'],
			['setForceStart', ['abc', true], 'setForceStart', 'value', 'true'],
			['setSuperSeeding', ['abc', true], 'setSuperSeeding', 'value', 'true'],
			['delete', ['abc', true], 'delete', 'deleteFiles', 'true'],
			['delete', ['abc', false], 'delete', 'deleteFiles', 'false'],
		];

		foreach ($cases as [$method, $args, $endpoint, $field, $expected]) {
			$this->queueJson([]);

			$this->client->$method(...$args);

			$params = $this->assertRequest('POST', '/api/v2/torrents/' . $endpoint);

			$this->assertSame($expected, $params[$field], $method . ' on ' . var_export(end($args), true));
		}
	}

	public function testGetDirectoryContentEncodesWithMetadataAsAStringFlag(): void
	{
		$this->queueJson([]);

		$this->client->getDirectoryContent('/data', 'all', true);

		$this->assertSame(
			['dirPath' => '/data', 'mode' => 'all', 'withMetadata' => 'true'],
			$this->lastQueryParams(),
		);
	}

	public function testGetDirectoryContentWithoutMetadata(): void
	{
		$this->queueJson([]);

		$this->client->getDirectoryContent('/data');

		$this->assertSame('false', $this->lastQueryParams()['withMetadata']);
	}

	public function testSetSpeedLimitsModeSendsOneOrZero(): void
	{
		$this->queueJson([]);
		$this->client->setSpeedLimitsMode(true);
		$this->assertSame('1', $this->assertRequest('POST', '/api/v2/transfer/setSpeedLimitsMode')['mode']);

		$this->queueJson([]);
		$this->client->setSpeedLimitsMode(false);
		$this->assertSame('0', $this->assertRequest('POST', '/api/v2/transfer/setSpeedLimitsMode')['mode']);
	}

	public function testSetSpeedLimitsMapsItsArgumentOrderOntoTheApiNames(): void
	{
		$this->queueJson([]);

		$this->client->setSpeedLimits(1, 2, 3, 4);

		$params = $this->assertRequest('POST', '/api/v2/transfer/setSpeedLimits');

		$this->assertSame(
            [
			'up_limit'     => '1',
			'dl_limit'     => '2',
			'alt_up_limit' => '3',
			'alt_dl_limit' => '4',
            ],
            $params,
		);
	}

	public function testGetDownloadLimitReturnsTheHashKeyedMap(): void
	{
		$this->queueJson(['abc' => 1048576]);

		$this->assertSame(['abc' => 1048576], $this->client->getDownloadLimit('abc'));
	}

	public function testSetShareLimitsSendsAllFiveValues(): void
	{
		$this->queueJson([]);

		$this->client->setShareLimits(['a', 'b'], 2.0, 3600, 1800, 'Stop', 'MatchAll');

		$params = $this->assertRequest('POST', '/api/v2/torrents/setShareLimits');

		$this->assertSame('a|b', $params['hashes']);
		$this->assertSame('2', $params['ratioLimit']);
		$this->assertSame('3600', $params['seedingTimeLimit']);
		$this->assertSame('1800', $params['inactiveSeedingTimeLimit']);
		$this->assertSame('Stop', $params['shareLimitAction']);
		$this->assertSame('MatchAll', $params['shareLimitsMode']);
	}

	/**
	 * The composite groups stay as raw arrays inside the DTO: server_state,
	 * categories, torrents, tags and trackers have no single-entity home, so
	 * they are carried verbatim rather than hydrated into child DTOs.
	 */
	public function testSyncMainDataCarriesCompositeGroupsAsRawArrays(): void
	{
		$this->queueJson(
            [
			'rid'          => 5,
			'full_update'  => true,
			'torrents'     => [['hash' => 'abc']],
			'tags'         => ['iso'],
			'categories'   => ['linux' => ['name' => 'linux']],
			'server_state' => ['dl_info_speed' => 1024],
            ]
		);

		$data = $this->client->syncMainData();

		$this->assertSame(5, $data->rid);
		$this->assertTrue($data->full_update);
		$this->assertSame(['iso'], $data->tags);
		$this->assertSame([['hash' => 'abc']], $data->torrents, 'Torrents stay raw arrays, not DTOs.');
		$this->assertSame(['linux' => ['name' => 'linux']], $data->categories);
		$this->assertSame(['dl_info_speed' => 1024], $data->server_state);
	}

	public function testRequestDecodesInvalidUtf8RatherThanFailing(): void
	{
		// qBittorrent can emit malformed bytes in a torrent name; the client
		// must substitute rather than lose the whole response.
		// Written literally: json_encode() would itself refuse the bad byte.
		$this->queueText("[{\"name\":\"bad \xB1 name\"}]");

		$torrents = $this->client->getTorrents();


		// json_decode()'s JSON_INVALID_UTF8_SUBSTITUTE already replaces the
		// bad byte with U+FFFD, so the payload decodes successfully and
		// normalizeArrayUtf8() then finds nothing left to repair.
		$this->assertSame("bad \u{FFFD} name", $torrents[0]->name);
	}

	public function testRequestReturnsAnEmptyArrayForAnEmptyBody(): void
	{
		$this->queueText('');

		$this->assertSame([], $this->client->getTorrents());
	}

	public function testRequestReturnsAnEmptyArrayForMalformedJson(): void
	{
		$this->queueText('{not json');

		$this->assertSame([], $this->client->getTorrents());
	}

	public function testRequestReturnsAnEmptyArrayForAnHtmlErrorPage(): void
	{
		// A reverse proxy in front of qBittorrent can answer 200 with HTML;
		// that must not become a parse exception for the caller.
		$this->queueText('<!DOCTYPE html><html><body>502</body></html>');

		$this->assertSame([], $this->client->getTorrents());
	}
}
