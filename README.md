# unkybadtouch/qbbittorrent

PHP client for the qBittorrent WebUI API v2 (5.0+), with a typed DTO layer
over the raw JSON responses. UTF-8 safe, PHP 8.4 (uses property hooks).

```php
use Blackout\Qbittorrent\Client;

$qb = new Client('http://localhost:8080', 'admin', 'adminadmin');

foreach ($qb->getTorrents(['filter' => 'downloading']) as $torrent) {
    echo "{$torrent->name}: {$torrent->progress_percent}%\n";
}
```

## Requirements

- PHP >= 8.4 (property hooks are used throughout the DTO layer)
- ext-mbstring
- guzzlehttp/guzzle ^7.0
- hassankhan/config ^3.2

## Install

```bash
composer require unkybadtouch/qbbittorrent
```

## Quick start

```php
use Blackout\Qbittorrent\Client;

$qb = new Client($baseUri, $username, $password);
```

`login()` is called automatically on first request. The auth cookie is
cached to `cache/cookies.json` (relative to the package's `src/` dir) for an
hour, so you don't re-authenticate on every script run; a 403 mid-session
triggers one automatic re-login + retry. Call `$qb->logout()` to clear both
the cookie and the cache file.

## Core concepts

**DTOs hydrate from raw API arrays.** Every `Client` method that returns a
single entity or list of entities returns `Base` subclasses
(`Blackout\Qbittorrent\DTO\*`), not raw arrays. Hydration is reflection-based:
public typed properties matching an array key get cast (scalars, backed
enums, nested DTOs); anything else is skipped silently — a field the API
doesn't return for a given call just stays uninitialized.

**DTOs carry behavior.** Most DTOs hold a reference to the `Client` that
created them and expose action/mutator methods that call back into it, e.g.
`$torrent->pause()` — no need to keep passing hashes/names back to `$qb`
yourself.

**Lazy relations.** `Torrent::$files`/`$trackers`/`$peers`/`$pieces`/`$webseeds`
are fetched on first access, not eagerly:

```php
$torrent = $qb->getTorrents(['hashes' => $hash])[0];
foreach ($torrent->files as $file) { ... } // fires getTorrentFiles() here
foreach ($torrent->files as $file) { ... } // cached, no second request
```

**JSON round-trip.** Every DTO implements `JsonSerializable` — `json_encode($torrent)`
emits only the initialized/loaded fields (uninitialized properties and
un-fetched lazy relations are omitted).

**Wire conventions**: booleans go over the wire as the strings `'true'`/`'false'`.
Any `Client` method taking a list (`$hashes`, `$tags`, `$urls`, ...) accepts
either a bare string or an array — a single item doesn't need to be wrapped.

## Usage examples

These walk through real end-to-end flows, with particular attention to
**nested DTOs** — DTOs that hold other DTOs, either eagerly (hydrated inline
from the same response) or lazily (fetched from a separate endpoint on first
property access).

### 1. Add a magnet, poll until done, list its files

```php
use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\Enum\TorrentState;

$qb = new Client('http://localhost:8080', 'admin', 'adminadmin');

$qb->addTorrentUrls($magnetUri, [
    'category' => 'linux',
    'paused'   => 'false',
    // never pass 'savepath' here (PROJECT.md gotcha — qBittorrent mishandles it
    // on /torrents/add); set the save path after the add instead:
    // $torrent->setSavePath('/data/downloads/linux')
]);

// addTorrentUrls()/addTorrentFile() don't return the new Torrent — the API
// doesn't echo it back — so fetch it once you know the hash (magnet URIs
// carry it in the `xt=urn:btih:...` param; for .torrent files, hash it
// yourself or grep the newest entry in the category)
$hash = strtolower(preg_match('/btih:([a-f0-9]{40})/i', $magnetUri, $m) ? $m[1] : '');

do {
    $torrent = $qb->getTorrents(['hashes' => $hash])[0] ?? null;
    usleep(500_000);
} while ($torrent === null || $torrent->state === TorrentState::META_DL);

while (!$torrent->is_complete) {
    sleep(2);
    $torrent = $qb->getTorrents(['hashes' => $hash])[0];
}

echo "{$torrent->name} finished ({$torrent->size_human})\n";

foreach ($torrent->files as $file) { // fires getTorrentFiles($hash) here, once
    echo "  {$file->basename} ({$file->size_human})\n";
}
```

### 2. Nested DTOs: walking every relation on a `Torrent`

`Torrent` is the clearest nested-DTO case in the library: one hydrated object
whose five collection properties are each backed by a *separate* API call,
fetched lazily and cached per-instance.

```php
$torrent = $qb->getTorrents(['hashes' => $hash])[0];

// Nothing below has fired a request yet — files/trackers/peers/pieces/webseeds
// are declared as hooked `get` properties (Torrent.php), not hydrated fields.

foreach ($torrent->files as $file) {          // -> Client::getTorrentFiles($hash)
    printf("  [%d] %-40s %6.2f%% prio=%s\n",
        $file->index, $file->basename, $file->progress_percent, $file->priority->label());
}

foreach ($torrent->trackers as $tracker) {    // -> Client::getTorrentTrackers($hash)
    printf("  %-50s %s (%s)\n", $tracker->url, $tracker->status->label(), $tracker->msg);
}

foreach ($torrent->peers as $peer) {          // -> Client::getTorrentPeers($hash)
    printf("  %-15s:%-5d %-20s %5.1f%%\n", $peer->ip, $peer->port, $peer->client, $peer->progress * 100);
}

$piecesHave = count(array_filter($torrent->pieces, fn ($p) => $p->is_downloaded)); // -> Client::getTorrentPiecesStates($hash)
echo "{$piecesHave}/{$torrent->pieces_num} pieces\n";

foreach ($torrent->webseeds as $seed) {       // -> Client::getTorrentWebSeeds($hash)
    echo "  {$seed->url}\n";
}

// Second access to any of these is free — cached in $torrent->_relations
$torrent->files; // no request

// Mutating through a relation invalidates its own cache so the next read refetches:
$torrent->addTrackers('udp://new-tracker.example.com:80');
$torrent->trackers; // -> Client::getTorrentTrackers($hash) again, includes the new one
```

`Torrent::$downloadable_files` is a computed nested-DTO filter — it reads
`$this->files` (triggering the same lazy fetch) and drops anything with
`FilePriority::DO_NOT_DOWNLOAD`:

```php
$toGrab = array_sum(array_map(fn ($f) => $f->size, $torrent->downloadable_files));
echo "Will download " . \Blackout\Helper::filesize($toGrab) . "\n";
```

### 3. Nested DTOs inside a plain array: the RSS feed/folder tree

Unlike `Torrent`'s relations (DTO holding DTOs via lazy fetch), `getRssFeeds()`
returns one *eagerly* hydrated structure where `Feed` DTOs are interleaved with
plain PHP arrays (folders have no DTO of their own) at arbitrary depth:

```php
use Blackout\Qbittorrent\DTO\Rss\Feed;

function walkRssTree(array $node, string $indent = ''): void
{
    foreach ($node as $name => $item) {
        if ($item instanceof Feed) {
            // hasError/isLoading/title etc. are only initialized when fetched
            // with withData=true — accessing them otherwise throws (uninitialized
            // typed property), so guard with isInitialized-style try/catch or,
            // as here, only read them when you know withData was passed
            $status = $item->hasError ? 'ERROR' : 'ok';
            echo "{$indent}{$name}  [{$item->url}]  {$status}\n";
        } else {
            echo "{$indent}{$name}/\n";
            walkRssTree($item, $indent . '    '); // recurse into the folder
        }
    }
}

walkRssTree($qb->getRssFeeds(['withData' => true]));

// Direct access once you know the path:
$feed = $qb->getRssFeeds()['tech']['linux-distros'];
$feed->setRefreshInterval(1800);
```

Each `Feed`, however deep in the tree, still knows its own full backslash-joined
`path` (e.g. `tech\linux-distros`) because `Client::hydrateRssItems()` threads
it through the recursion during hydration — that's the one field on `Feed`
that isn't a real API value (see the DTO reference below).

### 4. Two-level nested flow: search status → search results

```php
use Blackout\Qbittorrent\Enum\SearchStatus;

$id = $qb->startSearch('debian netinst iso', plugins: 'all', category: 'all');

do {
    sleep(1);
    $status = $qb->getSearchStatus($id)[0]; // Search\Status
} while ($status->status === SearchStatus::RUNNING);

echo "{$status->total} results\n";

foreach ($status->results(limit: 25) as $result) { // Search\Result[], via Status::results()
    printf("%-60s %5d seeds  %s\n", $result->fileName, $result->nbSeeders, $result->engineName);

    if ($result->nbSeeders > 10 && str_contains($result->fileName, 'netinst')) {
        $result->download(); // -> Client::downloadSearchTorrent()
    }
}

$status->delete(); // after this, don't call getSearchStatus($id) again — the id 404s
```

### 5. RSS rules: reading and rewriting the nested `torrentParams` array

`Rss\Rule::$torrentParams` is a raw nested array (the API's modern
add-torrent-options payload), and four legacy top-level properties are
one-way mirrors into it:

```php
$rule = $qb->getRssRules()['tv-releases'];

var_dump($rule->torrentParams);
// ['category' => 'tv', 'save_path' => '/data/tv', 'content_layout' => 'Original', ...]

$rule->assignedCategory = 'anime';        // also sets $rule->torrentParams['category'] = 'anime'
$rule->torrentParams['save_path'] = '/data/anime'; // or write the nested array directly — same effect

$rule->save(); // POSTs jsonSerialize() (minus the synthesized `name`) to setRule
```

### 6. Categories and torrents together

```php
$qb->createCategory('archived', savePath: '/data/archive');

foreach ($qb->getTorrentsByCategory('linux') as $torrent) {
    if ($torrent->is_complete && $torrent->ratio >= 2.0) {
        $torrent->setCategory('archived');   // Client::setTorrentCategory + local field update
        $torrent->setSavePath('/data/archive');
    }
}

$categories = $qb->getCategories();          // string-keyed array of Category
echo $categories['archived']->savePath;      // "/data/archive"
$categories['archived']->edit(savePath: '/data/archive/linux'); // moves + updates local field
```

### 7. Serializing nested DTOs to JSON

`jsonSerialize()` only emits properties that are actually initialized/loaded
— lazy relations you never touched are simply absent, not `null`:

```php
$torrent = $qb->getTorrents(['hashes' => $hash])[0];

echo json_encode($torrent, JSON_PRETTY_PRINT);
// { "hash": "...", "name": "...", ... }  <- no "files"/"trackers"/etc. keys yet

$torrent->files; // triggers the fetch

echo json_encode($torrent, JSON_PRETTY_PRINT);
// { "hash": "...", ..., "files": [ { "index": 0, "name": "...", ... }, ... ] }
// nested Torrent\File DTOs serialize themselves too — jsonSerialize() recurses naturally
```

### 8. Paging through the log instead of refetching everything

```php
$lastId = -1;
$allMessages = [];

do {
    $batch = $qb->getLog(['last_known_id' => $lastId]); // Log\Message[]
    $allMessages = [...$allMessages, ...$batch];
    $lastId = end($batch)?->id ?? $lastId;
} while (count($batch) > 0 && count($allMessages) < 5000);
```

### 9. Error handling

Every network/auth failure surfaces as a plain `\Exception` (Guzzle exceptions
are caught and rethrown, not passed through), and `addTorrentUrls()`/`addTorrentFile()`
explicitly throw when qBittorrent reports zero torrents added:

```php
try {
    $qb->addTorrentUrls($url);
} catch (\Exception $e) {
    // message is either the raw text/JSON body qBittorrent returned,
    // or an extracted error/message/reason field — see describeAddTorrentFailure()
    error_log("Add failed: {$e->getMessage()}");
}

try {
    $qb = (new Client($baseUri, $username, $wrongPassword))->login($username, $wrongPassword);
} catch (\Exception $e) {
    // "Authentication failed: ... (403)"
}
```

## DTO reference

### `Torrent` (`DTO\Torrent`)

From `getTorrents()` / `getTorrentsByCategory()` / `getTorrentsByHash()`. The
main "everything about one torrent" object.

Key fields: `hash`, `infohash_v1`, `infohash_v2`, `name`, `magnet_uri`, `comment`,
`state` (`Enum\TorrentState`), `progress` (0–1 float), `priority`, `size`,
`total_size`, `downloaded`, `uploaded`, `dlspeed`, `upspeed`, `ratio`,
`ratio_limit`, `share_limit_action` (`Enum\ShareLimitAction`),
`share_limits_mode` (`Enum\ShareLimitsMode`), `num_seeds`, `num_leechs`, `eta`,
`added_on`, `completion_on`, `category`, `tags` (comma-separated string),
`save_path`, `download_path`, `tracker`, `dl_limit`, `up_limit`, `auto_tmm`,
`super_seeding`, `seq_dl`, `force_start`, and more — see the file for the full
list (~60 fields).

Computed (not real API fields): `progress_percent` (float, 0–100),
`is_complete` (bool), `size_human` (string, e.g. `"1.2 GiB"`),
`downloadable_files` (`File[]`, excludes files marked do-not-download).

Lazy relations: `files` (`File[]`), `trackers` (`Tracker[]`), `peers` (`Peer[]`),
`pieces` (`Piece[]`), `webseeds` (`WebSeed[]`).

```php
$torrent = $qb->getTorrents(['hashes' => $hash])[0];

$torrent->addTags(['linux-iso', 'archive']);      // Client::addTorrentTags + local update
$torrent->removeTags('archive');
$torrent->setCategory('linux');
$torrent->setSavePath('/data/downloads/linux');
$torrent->setDownloadPath('/data/incomplete/linux');
$torrent->setComment('verified checksum');
$torrent->setTags(['a', 'b']);                    // replaces, not merges

$torrent->stop();
$torrent->start();
$torrent->recheck();
$torrent->reannounce();
$torrent->delete(deleteFiles: true);

$torrent->setDownloadLimit(500_000);              // bytes/sec, local field updates immediately
$torrent->setUploadLimit(100_000);
$torrent->setShareLimits(ratioLimit: 2.0, seedingTimeLimit: 1440, inactiveSeedingTimeLimit: -1);

$torrent->increasePrio();
$torrent->topPrio();
$torrent->setFilePrio([0, 1], priority: 7);        // FilePriority::MAXIMUM

$torrent->setLocation('/mnt/nas/downloads');
$torrent->rename('New Name');
$torrent->renameFile('old/path.mkv', 'new/path.mkv');
$torrent->renameFolder('old-dir', 'new-dir');

$torrent->setAutoManagement(true);
$torrent->toggleSequentialDownload();
$torrent->toggleFirstLastPiecePrio();
$torrent->setForceStart(true);
$torrent->setSuperSeeding(true);

$torrent->addTrackers(['udp://tracker.example.com:80']);
$torrent->editTracker('udp://old', 'udp://new');
$torrent->removeTrackers('udp://dead-tracker.example.com:80');
$torrent->addPeers(['1.2.3.4:6881']);
$torrent->addWebSeeds('https://example.com/webseed/');

$bytes = $torrent->downloadFile($torrent->files[0]); // raw file bytes
$torrentFileBytes = $torrent->export();               // the .torrent itself
```

`pause`/`resume` are deliberately *not* wired onto `Torrent` (updating `state`
locally would mean guessing the resulting enum value) — use `$qb->stop($hash)`
/ `$qb->start($hash)` and refetch if you need the new state.

### `Torrent\Properties` (`DTO\Torrent\Properties`)

From `getTorrentProperties($hash)`. A *different* field set from `Torrent`
(different endpoint, `/torrents/properties`), not a subset — e.g. it has both
`is_private` and `private`, and `dl_speed_avg`/`up_speed_avg` that `Torrent`
lacks.

```php
$props = $qb->getTorrentProperties($hash);
echo "{$props->seeds}/{$props->seeds_total} seeds, avg dl {$props->dl_speed_avg} B/s";
```

No action methods — read-only snapshot.

### `Torrent\File` (`DTO\Torrent\File`)

From `Torrent::$files` or `getTorrentFiles($hash)`.

Fields: `index`, `name`, `size`, `progress`, `priority` (`Enum\FilePriority`),
`is_seed`, `availability`, `piece_range`.

Computed: `progress_percent`, `is_complete`, `size_human`, `basename`,
`dirname`, `url_path` (rawurlencode'd per path segment, no host — prefix your
own download host).

```php
foreach ($torrent->files as $file) {
    if ($file->priority->isDoNotDownload()) continue;
    echo "{$file->basename}: {$file->progress_percent}%\n";
}

$bytes = $qb->downloadFile($hash, $torrent->files[2]); // or pass the index directly
```

### `Torrent\Tracker` (`DTO\Torrent\Tracker`)

From `Torrent::$trackers` or `getTorrentTrackers($hash)`.

Fields: `url`, `status` (`Enum\TrackerStatus`), `tier`, `num_peers`,
`num_seeds`, `num_leeches`, `num_downloaded`, `msg`; multi-tracker (v2) entries
also carry `name`, `updating`, `bt_version`, `next_announce`, `min_announce`,
`endpoints`.

```php
foreach ($torrent->trackers as $tracker) {
    if (!$tracker->status->isHealthy()) {
        echo "{$tracker->url}: {$tracker->msg}\n";
    }
}
```

No action methods — mutate via `Torrent::addTrackers()`/`editTracker()`/`removeTrackers()`.

### `Torrent\Peer` (`DTO\Torrent\Peer`)

From `Torrent::$peers` or `getTorrentPeers($hash)` (backed by
`/sync/torrentPeers`, not `/torrents/peers` — the latter 404s).

Fields: `ip`, `port`, `client`, `peer_id_client`, `country`, `country_code`,
`progress`, `dl_speed`, `up_speed`, `downloaded`, `uploaded`, `connection`,
`flags`, `flags_desc`, `relevance`, `files`, `contribution`, `host_name`,
`i2p_dest`.

```php
foreach ($torrent->peers as $peer) {
    echo "{$peer->ip}:{$peer->port} ({$peer->client}) {$peer->progress}\n";
}
```

### `Torrent\Piece` (`DTO\Torrent\Piece`)

From `Torrent::$pieces` or `getTorrentPiecesStates($hash)` (the raw API returns
a flat `[state, state, ...]` array; the client zips it into `{index, state}`
pairs).

Fields: `index`, `state` (`Enum\PieceState`). Computed: `is_downloaded`,
`is_downloading`.

```php
$have = array_filter($torrent->pieces, fn ($p) => $p->is_downloaded);
```

### `Torrent\WebSeed` (`DTO\Torrent\WebSeed`)

From `Torrent::$webseeds` or `getTorrentWebSeeds($hash)`. One field: `url`.
Mutate via `Torrent::addWebSeeds()`/`editWebSeed()`/`removeWebSeeds()`.

### `Category` (`DTO\Category`)

From `getCategories()`.

Fields: `name`, `savePath` (legacy camelCase, always a string), `download_path`
(snake_case, nullable — null for the built-in `RSS` category), `ratio_limit`,
`seeding_time_limit`, `inactive_seeding_time_limit`,
`share_limit_action` (`Enum\ShareLimitAction`), `share_limits_mode` (`Enum\ShareLimitsMode`).

```php
$categories = $qb->getCategories();
$categories['linux']->edit(savePath: '/data/linux');
$categories['old-stuff']->delete();
```

`edit()` only updates `name`/`savePath` — that's all `editCategory()` accepts
server-side; share-limit fields aren't editable through this endpoint.

### `Cookie` (`DTO\Cookie`)

From `getCookies()`; also constructed manually for `setCookies()` /
`importCookiesJson()` / `importCookieHeader()` / `importCurlCookies()` /
`importBrowserCookies()`.

Fields: `name`, `domain`, `path`, `value`, `expirationDate` (seconds since
epoch — the setter accepts an `int`, a date string, or any `DateTimeInterface`
and normalizes it).

```php
$qb->setCookies([
    new Cookie(['name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/', 'expirationDate' => '+30 days']),
]);

// or import from a browser export / Netscape cookie file:
$qb->importBrowserCookies('/home/me/.mozilla/.../cookies.sqlite', domains: 'example.com');
$qb->importCurlCookies(file_get_contents('cookies.txt'), domains: ['example.com']);
$qb->clearCookies(); // empties the store
```

`setCookies()` merges into the existing store by `name+domain+path` by
default; pass `merge: false` to replace it outright.

### `BuildInfo` (`DTO\BuildInfo`)

From `getBuildInfo()`. Flat, read-only: `bitness`, `boost`, `libtorrent`,
`openssl`, `platform`, `qt`, `zlib`.

```php
$info = $qb->getBuildInfo();
echo "libtorrent {$info->libtorrent} on {$info->platform}";
```

### `Transfer` (`DTO\Transfer`)

From `getTransferInfo()`. Global (not per-torrent) transfer state.

Fields: `connection_status`, `dht_nodes`, `dl_info_data`, `dl_info_speed`,
`dl_rate_limit`, `up_info_data`, `up_info_speed`, `up_rate_limit`,
`last_external_address_v4`, `last_external_address_v6`.

```php
$t = $qb->getTransferInfo();

$t->speedLimitsMode();        // bool — alt speed limits active?
$t->toggleSpeedLimitsMode();
$t->setDownloadLimit(1_000_000);  // global, bytes/sec — distinct from per-torrent limits
$t->setUploadLimit(500_000);
$t->banPeers(['1.2.3.4:6881', '5.6.7.8:6882']);
```

Note: qBittorrent rounds a submitted byte limit to the nearest KiB
server-side, but the local field is set to the exact value passed — it can
drift by up to ~1023 bytes from the server until you refetch.

### `MainData` (`DTO\MainData`)

From `syncMainData($query)` — the WebUI's polling endpoint, wraps everything
in one diffable payload. Composite/untyped: `rid`, `full_update`, `torrents`,
`torrents_removed`, `categories`, `categories_removed`, `tags`, `tags_removed`,
`trackers`, `trackers_removed`, `server_state` are all raw arrays (no natural
single-entity DTO home). Pass `['rid' => $lastRid]` to get an incremental diff
instead of the full state.

### `Log\Message` (`DTO\Log\Message`)

From `getLog($query)` (`/log/main`). Fields: `id`, `message`, `timestamp`,
`type` (`Enum\LogMessageType` — a bitmask on the wire, but each entry only
ever carries one bit). Flat, no action methods. `getLog()` defaults to
`last_known_id=-1` (returns everything, capped at 20000 entries) — pass
`['last_known_id' => $id]` to page forward.

### `Log\Peer` (`DTO\Log\Peer`)

From `getPeerLog($query)` (`/log/peers`). Fields: `id`, `ip`, `timestamp`,
`blocked`, `reason`. Flat, no action methods.

### `Search\Result` (`DTO\Search\Result`)

From `Search\Status::results()` or `getSearchResults($id)`.

Fields: `fileName`, `fileUrl`, `fileSize`, `nbSeeders`, `nbLeechers`,
`engineName`, `siteUrl`, `descrLink`, `pubDate`.

```php
foreach ($status->results() as $result) {
    if ($result->nbSeeders > 5) $result->download(); // downloadSearchTorrent()
}
```

### `Search\Status` (`DTO\Search\Status`)

From `getSearchStatus($id)`. Fields: `id`, `status` (`Enum\SearchStatus`:
`RUNNING`/`STOPPED`), `total`.

```php
$id = $qb->startSearch('debian iso', plugins: 'all', category: 'all');
$status = $qb->getSearchStatus($id)[0];

$status->results(limit: 20);
$status->stop();
$status->delete();
```

After `delete()`, don't call `getSearchStatus()` on that id again — the server
404s instead of returning an empty result once the id is gone.

### `Search\Plugin` (`DTO\Search\Plugin`)

From `getSearchPlugins()`. Fields: `name`, `fullName`, `url`, `version`,
`enabled`, `supportedCategories` (`{id, name}[]`).

```php
foreach ($qb->getSearchPlugins() as $plugin) {
    if (!$plugin->enabled) $plugin->enable();
}
$qb->getSearchPlugins()['legittorrents']->uninstall();
$qb->installSearchPlugin('https://example.com/my-plugin.py');
$qb->updateSearchPlugins();
```

### `Rss\Feed` (`DTO\Rss\Feed`)

From `getRssFeeds($query)` — `/rss/items` returns a tree keyed by feed/folder
name; a node with a `uid` key becomes a `Feed`, anything else is a plain
nested array (no `Folder` DTO — recurse yourself).

Fields: `path` (synthesized from the node's position in the tree, not a real
API field), `uid`, `url`, `refreshInterval` (only present when > 0). With
`withData=true`: `title`, `lastBuildDate`, `isLoading`, `hasError`, `articles`.

```php
$feeds = $qb->getRssFeeds(['withData' => true]);
$linux = $feeds['tech']['linux-distros']; // nested by folder

$linux->setUrl('https://example.com/new-feed.xml');
$linux->setRefreshInterval(1800);
$linux->refresh();
$linux->markAsRead();
$linux->move('archive/linux-distros');
$linux->remove();

$qb->addRssFolder('tech');
$qb->addRssFeed('https://example.com/feed.xml', 'tech/new-feed');
```

### `Rss\Rule` (`DTO\Rss\Rule`)

From `getRssRules()`.

Fields: `name` (synthesized from the rule's key, not a real field), `enabled`,
`priority`, `useRegex`, `mustContain`, `mustNotContain`, `episodeFilter`,
`affectedFeeds`, `lastMatch`, `ignoreDays`, `smartFilter`,
`previouslyMatchedEpisodes`, `torrentParams` (raw array — modern field, docs
omit it). Deprecated but still-writable-through fields `addPaused`,
`torrentContentLayout`, `savePath`, `assignedCategory` mirror themselves into
`torrentParams` on every assignment (that's what the server actually reads).

Unlike other DTOs there's no per-field endpoint — only whole-object
`setRule` — so this one gets `save()` instead of individual setters:

```php
$rules = $qb->getRssRules();
$rule = $rules['new-releases'];

$rule->mustContain = '1080p|2160p';
$rule->assignedCategory = 'tv';       // also sets torrentParams['category']
$rule->save();                         // POSTs the whole object

$rule->rename('tv-releases');
$rule->clone('tv-releases-copy');      // undocumented endpoint, not live until qBittorrent ships it past 5.2.2
$rule->matchingArticles();
$rule->delete();

$qb->addRssRule('new-rule', [
    'enabled' => true,
    'mustContain' => 'S\d{2}E\d{2}',
    'affectedFeeds' => ['tech\\linux-distros'],
    'torrentParams' => ['category' => 'tv', 'save_path' => '/data/tv'],
]);
```

### `Preferences` (`DTO\Preferences`)

From `getPreferences()`. Flat but huge (~220 fields covering every WebUI
settings-page option: connection, BitTorrent, download paths, proxy, WebUI
security, RSS, mail notifications, scheduler, etc). Generated from a live
response rather than hand-typed — see the source file for the full list.
Notable: `share_limits_mode` (`Enum\ShareLimitsMode`), `max_ratio` (float).

Read-only DTO — there's no per-field setter wired up, because the API itself
has none. Push changes with the raw `Client` method instead, which PATCHes
only the keys you pass:

```php
$prefs = $qb->getPreferences();
echo $prefs->web_ui_port;

$qb->setPreferences([
    'dl_limit' => 5_000_000,
    'max_ratio' => 2.0,
    'max_ratio_enabled' => true,
]);
```

## Enums

All under `Blackout\Qbittorrent\Enum`, all backed, all use the `HasLabel`
trait (`->label()` → title-cased name, e.g. `TorrentState::STALLED_DL->label()`
→ `"Stalled dl"`).

| Enum | Backing | Cases |
|---|---|---|
| `TorrentState` | string | `ERROR`, `MISSING_FILES`, `DOWNLOADING`, `UPLOADING`, `STOPPED_DL`, `STOPPED_UP`, `QUEUED_DL`, `QUEUED_UP`, `STALLED_DL`, `STALLED_UP`, `CHECKING_DL`, `CHECKING_UP`, `CHECKING_RESUME_DATA`, `FORCED_DL`, `FORCED_UP`, `META_DL`, `FORCED_META_DL`, `MOVING`, `UNKNOWN` |
| `FilePriority` | int | `DO_NOT_DOWNLOAD`(0), `NORMAL`(1), `HIGH`(6), `MAXIMUM`(7) — plus `isDoNotDownload()`/`isNormal()`/`isHigh()` |
| `TrackerStatus` | int | `DISABLED`(0), `NOT_CONTACTED`(1), `WORKING`(2), `NOT_WORKING`(4), `TRACKER_ERROR`(5), `UNREACHABLE`(6) — plus `isHealthy()`/`isActive()` |
| `PieceState` | int | `NOT_DOWNLOADED`(0), `DOWNLOADING`(1), `DOWNLOADED`(2) |
| `LogMessageType` | int | `NORMAL`(1), `INFO`(2), `WARNING`(4), `CRITICAL`(8) |
| `SearchStatus` | string | `RUNNING`, `STOPPED` |
| `ShareLimitAction` | string | `DEFAULT`, `STOP`, `REMOVE`, `REMOVE_WITH_CONTENT`, `ENABLE_SUPER_SEEDING` |
| `ShareLimitsMode` | string | `DEFAULT`, `MATCH_ANY`, `MATCH_ALL` |

## Client coverage

`Client` covers the full qBittorrent WebUI API v2 surface: `auth`, `app`
(version/preferences/cookies/directory browsing), `log`, `sync`, `transfer`,
`torrents` (info/add/delete/manage/trackers/peers/priority/limits/tags/
categories), `rss` (feeds/rules), `search`, and `torrentcreator`. Methods that
return a single entity or entity list return the matching DTO above; the rest
(composite/system-info endpoints with no natural single-entity shape) return
raw arrays. See `src/Qbittorrent/Client.php` for the full method list.

## Notes

- Coding style: tabs, Allman braces (see `phpcs.xml` in the `qbittorrent-tools`
  repo — not duplicated here).
- Consumed by `UnkyBadTouch/qbittorrent-tools` as a composer VCS dependency —
  a breaking change here (namespace/signature) needs a version bump and
  coordinated update there.
