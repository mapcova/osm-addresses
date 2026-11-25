<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use Closure;
use MapCova\OsmAddresses\Osm\OsmExtractor;
use MapCova\OsmAddresses\Sources\Chunk;
use MapCova\OsmAddresses\Sources\ExtractSource;
use MapCova\OsmAddresses\Sources\Source;
use MapCova\OsmAddresses\Support\SafeHttpClient;
use MapCova\OsmAddresses\Support\SourceChangedException;
use MapCova\OsmAddresses\Text\TextNormalizer;
use MapCova\OsmAddresses\TextRules;
use PDO;

/**
 * Builds the database of one country from its source, in slices that fit a
 * time budget and resume from a {@see BuildState}. It gets an HTTP client
 * (the command line gives it one that reads a local file), a folder, a {@see BuildBudget} and a state,
 * and hands back the new state.
 *
 * Phases, each cut into units that end with a checkpoint:
 * plan, then for each chunk download → (extract, for an OpenStreetMap extract: addresses and way centres to a TSV) →
 * ingest (CSV into a staging table) → finalize (sort, number the addresses, aggregate the streets), then streets
 * (rank, R-Tree), fts, verify, publish (an atomic rename).
 *
 * Every unit is idempotent: what it writes and the marker that says it is
 * done are committed together, in one database file (the build file for the
 * markers of the result, the staging file for those of the staging work), so
 * a build killed between a commit and its checkpoint redoes nothing twice.
 * The database being served is never touched before the final rename.
 */
final class DatasetBuilder
{
    // Estimated seconds of one unit, a third of the pace of a developer's laptop:
    // a sliced build yields when its budget cannot afford the next one.
    private const DOWNLOAD_COST = 3.0;
    private const INGEST_COST = 2.0;
    private const FINALIZE_COST = 8.0;
    private const STREETS_COST = 15.0;
    private const RTREE_COST = 5.0;
    private const FTS_COST = 5.0;
    private const MERGE_COST = 5.0;
    private const VERIFY_COST = 20.0;


    private const INSERT_ROWS = 66;
    private const COLUMNS = 15;

    /** A file this many times the size the source expects is not the file it was written for. */
    private const SIZE_CEILING_FACTOR = 4;
    private const MAX_RESETS = 3;
    private const BBOX_CELL_E6 = 500_000;
    private const DISK_HEADROOM = 67_108_864;

    private readonly ChunkDownloader $downloader;

    /** @var Closure(string): ?float */
    private readonly Closure $freeBytes;

    /** @var Closure(): ?int */
    private readonly Closure $quotaLeft;

    private ?PDO $db = null;

    private BuildBudget $budget;

    private int $units = 0;

    /** @var (callable(BuildState, int, int): void)|null */
    private $checkpoint = null;

    /**
     * @param string                       $dataDir   the work folder
     * @param (Closure(string): ?float)|null $freeBytes free space of a folder; the real one when null
     * @param (Closure(): ?int)|null       $quotaLeft bytes left under the data quota, `null` for none
     * @param int                          $extractBlobs blobs of an OpenStreetMap extract an extraction unit reads
     */
    public function __construct(
        private readonly ExtractSource $source,
        private readonly SafeHttpClient $http,
        private readonly string $dataDir,
        ?Closure $freeBytes = null,
        ?Closure $quotaLeft = null,
        int $rangeBytes = ChunkDownloader::RANGE_BYTES,
        private readonly int $batchRows = 50_000,
        private readonly int $ftsSlice = 50_000,
        private readonly int $rtreeSlice = 100_000,
        private readonly int $extractBlobs = 256,
    ) {
        $this->downloader = new ChunkDownloader($http, $rangeBytes);
        $this->freeBytes = $freeBytes ?? static function (string $dir): ?float {
            $free = @disk_free_space($dir);

            return $free === false ? null : $free;
        };
        $this->quotaLeft = $quotaLeft ?? static fn (): ?int => null;
        $this->budget = new class () implements BuildBudget {
            public function canAfford(float $seconds): bool
            {
                return true;
            }

            public function shouldStop(): bool
            {
                return false;
            }
        };
    }

    public static function newState(Source $source, string $buildId): BuildState
    {
        return new BuildState($buildId, $source->country());
    }

    public static function buildDir(string $dataDir): string
    {
        return $dataDir . '/.build';
    }

    public static function finalPath(string $dataDir, string $country): string
    {
        return $dataDir . '/' . strtolower($country) . '.sqlite';
    }

    /**
     * Progress as `[done, total, phase]`, for a progress display.
     *
     * @return array{int, int, string}
     */
    public static function progress(BuildState $state): array
    {
        $total = max(1, $state->totalBytes * 2 + $state->streetsEstimate * 2);
        $bytes = $state->doneBytes * 2;

        $done = match ($state->phase) {
            BuildState::PLAN => 0,
            BuildState::DOWNLOAD => $bytes + $state->downloaded,
            BuildState::EXTRACT, BuildState::INGEST, BuildState::FINALIZE => $bytes + (int) $state->size + $state->extracted,
            BuildState::STREETS => $state->totalBytes * 2 + intdiv($state->streetsEstimate, 2),
            BuildState::FTS => $state->totalBytes * 2 + $state->streetsEstimate,
            BuildState::VERIFY, BuildState::PUBLISH => $state->totalBytes * 2 + (int) ($state->streetsEstimate * 1.9),
            default => $total,
        };

        return [min($done, $total), $total, $state->phase];
    }

    /**
     * Delete what a build of this country left in the build folder.
     */
    public function cleanup(): void
    {
        $this->closeDatabases();

        foreach (glob(self::buildDir($this->dataDir) . '/' . strtolower($this->source->country()) . '-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Run units until the budget cannot afford the next one, or the build is
     * done. At least one unit runs, so a tick always makes progress.
     *
     * @param (callable(BuildState, int, int): void)|null $checkpoint called with the state after each unit
     *
     * @throws PermanentBuildException       for a failure no retry can fix
     * @throws \MapCova\OsmAddresses\Support\HttpClientException for a transport failure
     */
    public function run(BuildState $state, BuildBudget $budget, ?callable $checkpoint = null): BuildState
    {
        $this->budget = $budget;
        $this->checkpoint = $checkpoint;
        $this->units = 0;

        try {
            while (!$state->isDone()) {
                $continue = match ($state->phase) {
                    BuildState::PLAN => $this->plan($state),
                    BuildState::DOWNLOAD => $this->download($state),
                    BuildState::EXTRACT => $this->extract($state),
                    BuildState::INGEST => $this->ingest($state),
                    BuildState::FINALIZE => $this->finalize($state),
                    BuildState::STREETS => $this->streets($state),
                    BuildState::FTS => $this->fts($state),
                    BuildState::VERIFY => $this->verify($state),
                    BuildState::PUBLISH => $this->publish($state),
                    default => throw new PermanentBuildException('err_unknown_phase'),
                };

                if (!$continue) {
                    break;
                }
            }
        } finally {
            $this->closeDatabases();
        }

        return $state;
    }

    private function take(float $cost): bool
    {
        if ($this->units > 0 && ($this->budget->shouldStop() || !$this->budget->canAfford($cost))) {
            return false;
        }

        ++$this->units;

        return true;
    }

    private function save(BuildState $state): void
    {
        if ($this->checkpoint !== null) {
            [$done, $total] = self::progress($state);
            ($this->checkpoint)($state, $done, $total);
        }
    }

    // --- paths and connections --------------------------------------------------------------------------

    private function path(BuildState $state, string $suffix): string
    {
        if (!BuildState::isValidId($state->buildId)) {
            throw new PermanentBuildException('err_build_id');
        }

        return self::buildDir($this->dataDir) . '/' . strtolower($state->country) . '-' . $state->buildId . $suffix;
    }

    private function db(BuildState $state): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $db = new PDO('sqlite:' . $this->path($state, '.sqlite'));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA cache_size=-8000');
        self::attachStaging($db, $this->path($state, '.staging.sqlite'));
        $db->exec('PRAGMA stg.synchronous=OFF');
        $db->exec('PRAGMA stg.cache_size=-8000');

        return $this->db = $db;
    }

    /**
     * Attach the staging database as `stg`. The path is written as a quoted literal, not a bound parameter: under
     * open_basedir, PHP's SQLite authorizer refuses an ATTACH whose file name it cannot see
     * when the statement is prepared ("23 not authorized").
     */
    public static function attachStaging(PDO $db, string $path): void
    {
        $quoted = $db->quote($path);
        if ($quoted === false) {
            throw new \RuntimeException('cannot quote the staging database path for ATTACH');
        }
        $db->exec('ATTACH DATABASE ' . $quoted . ' AS stg');
    }

    private function closeDatabases(): void
    {
        $this->db = null;
    }

    private function setMeta(PDO $db, string $key, string|int $value): void
    {
        $db->prepare('INSERT OR REPLACE INTO build_meta (key, value) VALUES (?, ?)')->execute([$key, (string) $value]);
    }

    private function getMeta(PDO $db, string $key, string $default): string
    {
        $statement = $db->prepare('SELECT value FROM build_meta WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : $default;
    }

    /**
     * A date as a unix time, read in UTC when the text names no zone (never in the server's own zone, which would
     * move a day by the offset): an HTTP date or an ISO 8601 one with its zone is read as written, or the given
     * `$format` (no time part: midnight UTC). `false` when it is not a date.
     */
    private static function utc(string $value, ?string $format = null): int|false
    {
        $utc = new \DateTimeZone('UTC');

        if ($format !== null) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);

            return $date === false ? false : $date->getTimestamp();
        }

        try {
            return (new \DateTimeImmutable($value, $utc))->getTimestamp();
        } catch (\Exception) {
            return false;
        }
    }

    private static function scalar(PDO $db, string $sql): mixed
    {
        $statement = $db->query($sql);

        return $statement === false ? null : $statement->fetchColumn();
    }

    private function freeIn(string $dir): ?float
    {
        return ($this->freeBytes)($dir);
    }

    // --- plan -------------------------------------------------------------------------------------------

    private function plan(BuildState $state): bool
    {
        $dir = self::buildDir($this->dataDir);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PermanentBuildException('err_build_dir_create');
        }

        [$head, $version, $largest] = $this->probeSource();

        if ($head['size'] > self::SIZE_CEILING_FACTOR * $this->source->approxBytes()) {
            throw new PermanentBuildException('err_source_size', [$state->country, (int) ceil($head['size'] / 1_000_000)]);
        }

        $final = self::finalPath($this->dataDir, $state->country);
        $existing = is_file($final) ? (int) filesize($final) : 0;
        $estimate = SpaceEstimator::estimate($this->source, $head['size'], $existing, $this->freeIn($dir), ($this->quotaLeft)(), $largest);

        if (!$estimate->fits()) {
            [$key, $params] = $estimate->keyed('err_no_space_build', [$state->country]);

            throw new InsufficientSpaceException($key, $params);
        }

        $this->cleanup();

        $trigram = $this->source->hasTrigram();
        $main = new PDO('sqlite:' . $this->path($state, '.sqlite'));
        $main->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::create($main, $trigram);
        $staging = new PDO('sqlite:' . $this->path($state, '.staging.sqlite'));
        $staging->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::createStaging($staging);
        $main = null;
        $staging = null;

        $state->totalBytes = $head['size'];
        $state->streetsEstimate = (int) ($head['size'] / 430);
        $state->version = $version;
        $state->phase = BuildState::DOWNLOAD;
        $this->save($state);

        return true;
    }

    /**
     * Size of the extract and its version signal (the ETag and Last-Modified of its URL).
     *
     * @return array{array{size: int, etag: ?string, last_modified: ?string, sha256: ?string, url: string}, array{etag: ?string, last_modified: ?string}, ?int} the head, the version, and the size of the largest file
     */
    private function probeSource(): array
    {
        $head = $this->downloader->probe($this->source->versionUrl());

        return [$head, ['etag' => $head['etag'], 'last_modified' => $head['last_modified']], $head['size']];
    }

    /** Where a chunk is downloaded from. */
    private function chunkUrl(Chunk $chunk): string
    {
        return $chunk->url;
    }

    // --- download ---------------------------------------------------------------------------------------

    private function download(BuildState $state): bool
    {
        $chunks = $this->source->chunks();

        if ($state->chunk >= count($chunks)) {
            $state->phase = BuildState::STREETS;
            $this->save($state);

            return true;
        }

        $chunk = $chunks[$state->chunk];

        $path = $this->path($state, '.chunk');
        $probed = false;

        // Nothing pins the file across ticks without a strong validator: start it over rather than guess.
        if ($state->size !== null && $state->downloaded > 0 && $state->downloaded < $state->size && !ChunkDownloader::isStrong($state->etag)) {
            $this->startChunkOver($state, $path, false);

            return true;
        }

        if ($state->size === null) {
            if (!$this->take(1.0)) {
                return false;
            }

            $probe = $this->downloader->probe($this->chunkUrl($chunk));
            $free = $this->freeIn(dirname($path));

            if ($probe['size'] > self::SIZE_CEILING_FACTOR * $this->source->largestChunkBytes()) {
                throw new PermanentBuildException('err_source_size', [$state->country, (int) ceil($probe['size'] / 1_000_000)]);
            }

            if ($free !== null && $free < $probe['size'] + self::DISK_HEADROOM) {
                throw new InsufficientSpaceException('err_no_space_chunk', [$state->country, (int) ceil($probe['size'] / 1_000_000)]);
            }

            $this->removeChunk($path);
            $state->size = $probe['size'];
            $state->etag = $probe['etag'];
            $state->lastModified = $probe['last_modified'];
            $state->sha256 = $probe['sha256'];
            $state->url = $probe['url'];
            $state->downloaded = 0;
            $state->versions[$chunk->key] = ['etag' => $probe['etag'], 'last_modified' => $probe['last_modified']];
            $this->save($state);
        }

        while ($state->downloaded < $state->size) {
            if (!$this->take(self::DOWNLOAD_COST)) {
                return false;
            }

            try {
                $state->downloaded = $chunk->ranged
                    ? $this->downloader->fetch($state->url ?? $chunk->url, $path, $state->downloaded, $state->size, $state->etag, $state->lastModified)
                    : $this->downloader->fetchWhole($state->url ?? $chunk->url, $path, $state->size);
            } catch (SourceChangedException) {
                $this->startChunkOver($state, $path);

                return true;
            }

            $this->save($state);
        }

        if ($state->size === 0) {
            touch($path);
        } elseif (!is_file($path) || filesize($path) !== $state->size || ($state->sha256 !== null && hash_file('sha256', $path) !== $state->sha256)) {
            $this->startChunkOver($state, $path);

            return true;
        }

        $state->phase = BuildState::EXTRACT;
        $state->extracted = 0;
        $this->save($state);

        return true;
    }

    private function startChunkOver(BuildState $state, string $path, bool $count = true): void
    {
        if ($count && ++$state->resets > self::MAX_RESETS) {
            throw new PermanentBuildException('err_source_moving');
        }

        $this->removeChunk($path);
        $state->size = null;
        $state->downloaded = 0;
        $state->extracted = 0;
        $state->etag = null;
        $state->lastModified = null;
        $state->sha256 = null;
        $state->url = null;
        $this->save($state);
    }

    /**
     * The disk, and the data quota, still hold what is left to do: the build must stop
     * before it fills the disk the main database lives on.
     */
    private function assertRoom(BuildState $state): void
    {
        $free = $this->freeIn(dirname($this->path($state, '.sqlite')));
        $quota = ($this->quotaLeft)();

        if (($free !== null && $free < self::DISK_HEADROOM) || ($quota !== null && $quota < self::DISK_HEADROOM)) {
            throw new InsufficientSpaceException('err_disk_full', [$state->country]);
        }
    }

    /** A downloaded chunk, and what the extraction of an OpenStreetMap extract left next to it (`<chunk>.addr.tsv`…). */
    private function removeChunk(string $path): void
    {
        @unlink($path);
        $prefix = basename($path) . '.';

        foreach (scandir(dirname($path)) ?: [] as $name) {
            if (str_starts_with($name, $prefix)) {
                @unlink(dirname($path) . '/' . $name);
            }
        }
    }

    // --- extract ----------------------------------------------------------------------------------------

    /**
     * The addresses of an OpenStreetMap extract, in units of {@see OsmExtractor} (each ends with a checkpoint of its own
     * state file next to the chunk); the state here only says the phase, and `extracted` feeds the progress.
     */
    private function extract(BuildState $state): bool
    {
        $path = $this->path($state, '.chunk');
        $extractor = new OsmExtractor($path, $this->source->languages(), $this->extractBlobs);

        // The file is gone (the build folder was cleaned): fetch it again, which counts against the resets.
        if ($extractor->needsPbf() && !is_file($path)) {
            $this->startChunkOver($state, $path);
            $state->phase = BuildState::DOWNLOAD;
            $this->save($state);

            return true;
        }

        $checksum = $extractor->needsChecksum() ? $this->publishedMd5($state, $this->source->chunks()[$state->chunk]) : null;
        $take = function (float $cost) use ($state): bool {
            if (!$this->take($cost)) {
                return false;
            }

            $this->assertRoom($state);

            return true;
        };
        $finished = $extractor->run($take, function () use ($state, $extractor): void {
            $state->extracted = (int) round($extractor->progress() * (int) $state->size);
            $this->save($state);
        }, $checksum);

        if (!$finished) {
            return false;
        }

        $state->phase = BuildState::INGEST;
        $state->extracted = (int) $state->size;
        $this->save($state);

        return true;
    }

    /**
     * The md5 the publisher gives for the file just downloaded (`<file>.md5`, one line `<md5>  <name>`, next to the dated
     * file Geofabrik redirects to), or `null` when it gives none.
     */
    private function publishedMd5(BuildState $state, Chunk $chunk): ?string
    {
        $answer = $this->http->get(($state->url ?? $chunk->url) . '.md5');

        if ($answer['status'] !== 200 || preg_match('/^([0-9a-f]{32})\b/i', trim($answer['body']), $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    // --- ingest -----------------------------------------------------------------------------------------

    private function ingest(BuildState $state): bool
    {
        $db = $this->db($state);
        $chunk = $this->source->chunks()[$state->chunk];
        $index = $state->chunk;
        $buckets = $this->source->bucketCount();

        $statement = $db->prepare('SELECT byte_offset, line, rows, rejected, done FROM stg.progress WHERE chunk = ?');
        $statement->execute([$index]);
        $progress = $statement->fetch(PDO::FETCH_NUM);
        $statement->closeCursor();

        if ($progress === false) {
            $db->beginTransaction();
            $db->prepare('INSERT INTO stg.progress (chunk, byte_offset, line, rows, rejected, done) VALUES (?, 0, 0, 0, 0, 0)')->execute([$index]);

            for ($bucket = 0; $bucket < $buckets; ++$bucket) {
                Schema::createStagingTable($db, $index, $bucket);
            }

            $db->commit();
            $progress = [0, 0, 0, 0, 0];
        }

        [$offset, $line, $rows, $rejected, $done] = array_map('intval', $progress);

        if ($done === 1) {
            $state->phase = BuildState::FINALIZE;
            $state->bucket = 0;
            $this->save($state);

            return true;
        }

        $path = $this->path($state, '.chunk');

        $reader = $this->source->reader($path, $chunk);
        $header = $reader->header();

        if ($header === null) {
            $db->prepare('UPDATE stg.progress SET done = 1 WHERE chunk = ?')->execute([$index]);
            $state->phase = BuildState::FINALIZE;
            $state->bucket = 0;
            $this->save($state);

            return true;
        }

        $columns = $this->source->columns($header);
        $reader->seek($offset);
        $insert = [];

        while (true) {
            if (!$this->take(self::INGEST_COST)) {
                return false;
            }

            $this->assertRoom($state);
            $db->beginTransaction();
            $count = 0;
            $record = null;
            $pending = array_fill(0, $buckets, []);

            while ($count < $this->batchRows && ($record = $reader->next()) !== null) {
                ++$count;
                ++$line;
                $offset = $reader->cursor();

                $row = $this->source->row($record, $columns);

                if ($row === null) {
                    ++$rejected;

                    continue;
                }

                $key = $row->streetKey();
                $bucket = $buckets === 1 ? 0 : crc32($key) % $buckets;
                array_push($pending[$bucket], $line, $key, $row->name, $row->postcode, $row->city, $row->cityCode, $row->district, $row->number, $row->sortNumber(), $row->suffix, $row->latE6, $row->lonE6, $row->extra === '' ? null : $row->extra, $row->names, $row->pc);
                ++$rows;

                if (count($pending[$bucket]) >= self::INSERT_ROWS * self::COLUMNS) {
                    $this->flush($db, $index, $bucket, $pending[$bucket], $insert);
                }

            }

            foreach (array_keys($pending) as $bucket) {
                $this->flush($db, $index, $bucket, $pending[$bucket], $insert);
            }

            $finished = $record === null;

            $db->prepare('UPDATE stg.progress SET byte_offset = ?, line = ?, rows = ?, rejected = ?, done = ? WHERE chunk = ?')
                ->execute([$offset, $line, $rows, $rejected, $finished ? 1 : 0, $index]);
            $db->commit();

            if ($finished) {
                $state->phase = BuildState::FINALIZE;
                $state->bucket = 0;
                $this->save($state);

                return true;
            }

            $this->save($state);
        }
    }

    /**
     * Insert the pending rows of a bucket with multi-row statements (fewer round
     * trips; at most 66 rows of 15 values, under the 999 variables of older SQLite).
     *
     * @param list<mixed>                  $values flattened rows, emptied
     * @param array<string, \PDOStatement> $statements cache by table and row count
     */
    private function flush(PDO $db, int $chunk, int $bucket, array &$values, array &$statements): void
    {
        $rows = intdiv(count($values), self::COLUMNS);

        if ($rows === 0) {
            return;
        }

        $key = $bucket . '/' . $rows;

        if (!isset($statements[$key])) {
            $prepared = $db->prepare(sprintf(
                'INSERT OR IGNORE INTO stg.%s (line, skey, name, postcode, city, city_code, district, number, nsort, suffix, lat_e6, lon_e6, extra, names, pc) VALUES %s',
                Schema::stagingTable($chunk, $bucket),
                implode(',', array_fill(0, $rows, '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')),
            ));

            if ($prepared === false) {
                throw new TransientBuildException('err_staging');
            }

            $statements[$key] = $prepared;
        }

        $statements[$key]->execute($values);
        $values = [];
    }

    // --- finalize ---------------------------------------------------------------------------------------

    private function finalize(BuildState $state): bool
    {
        $buckets = $this->source->bucketCount();

        if ($state->bucket >= $buckets) {
            $this->removeChunk($this->path($state, '.chunk'));
            $state->doneBytes += (int) $state->size;
            $state->extracted = 0;
            ++$state->chunk;
            $state->bucket = 0;
            $state->size = null;
            $state->downloaded = 0;
            $state->etag = null;
            $state->lastModified = null;
            $state->sha256 = null;
            $state->resets = 0;

            $state->phase = $state->chunk >= count($this->source->chunks()) ? BuildState::STREETS : BuildState::DOWNLOAD;
            $this->save($state);

            return true;
        }

        if (!$this->take(self::FINALIZE_COST)) {
            return false;
        }

        $this->assertRoom($state);
        $this->finalizeBucket($state, $state->chunk, $state->bucket);
        ++$state->bucket;
        $this->save($state);

        return true;
    }

    private function finalizeBucket(BuildState $state, int $chunk, int $bucket): void
    {
        $db = $this->db($state);
        $table = Schema::stagingTable($chunk, $bucket);
        $source = $chunk * 1000 + $bucket;

        $marked = $db->prepare('SELECT 1 FROM finalized WHERE chunk = ? AND bucket = ?');
        $marked->execute([$chunk, $bucket]);
        $isMarked = $marked->fetchColumn() !== false;
        $marked->closeCursor();
        $exists = $db->prepare("SELECT 1 FROM stg.sqlite_master WHERE type = 'table' AND name = ?");
        $exists->execute([$table]);
        $tableExists = $exists->fetchColumn() !== false;
        $exists->closeCursor();

        if (!$isMarked && $tableExists) {
            // Sort once into a table whose row ids are the new address ids (per bucket).
            $db->exec('DROP TABLE IF EXISTS stg.sorted');
            $db->exec('CREATE TABLE stg.sorted (aid INTEGER PRIMARY KEY, skey TEXT, name TEXT, postcode TEXT, city TEXT, city_code TEXT, district TEXT, number TEXT, suffix TEXT, lat_e6 INTEGER, lon_e6 INTEGER, extra TEXT, names TEXT, pc TEXT)');
            // Boxes of a building share its number: the first line read stands for them all.
            $only = $this->source->dedupeHouse() ? sprintf('WHERE line IN (SELECT MIN(line) FROM stg.%s GROUP BY skey, number, suffix)', $table) : '';
            $db->exec(sprintf(
                'INSERT INTO stg.sorted (skey, name, postcode, city, city_code, district, number, suffix, lat_e6, lon_e6, extra, names, pc) SELECT skey, name, postcode, city, city_code, district, number, suffix, lat_e6, lon_e6, extra, names, pc FROM stg.%s %s ORDER BY skey, nsort, number, suffix',
                $table,
                $only,
            ));

            $base = (int) self::scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM main.address');

            // The street aggregates go to the staging file first, replaced as a whole if this runs again.
            Schema::createStreetTmp($db);
            $db->beginTransaction();
            $db->exec('DELETE FROM stg.street_tmp WHERE src = ' . $source);
            $db->exec(sprintf(
                'INSERT INTO stg.street_tmp (skey, src, name, postcode, city, city_code, district, first_address, n, min_lat, max_lat, min_lon, max_lon, sum_lat, sum_lon, extra, names)
                 SELECT skey, %d, MIN(name), MIN(postcode), MIN(city), MIN(city_code), MIN(district), MIN(aid) + %d, COUNT(*), MIN(lat_e6), MAX(lat_e6), MIN(lon_e6), MAX(lon_e6), SUM(lat_e6), SUM(lon_e6), MAX(extra), MAX(names)
                 FROM stg.sorted GROUP BY skey',
                $source,
                $base,
            ));
            $db->commit();

            $db->beginTransaction();
            $db->exec(sprintf('INSERT INTO main.address (id, number, suffix, lat_e6, lon_e6, pc) SELECT aid + %d, number, suffix, lat_e6, lon_e6, pc FROM stg.sorted ORDER BY aid', $base));
            $db->prepare('INSERT INTO main.finalized (chunk, bucket) VALUES (?, ?)')->execute([$chunk, $bucket]);
            $db->commit();
        }

        if ($tableExists) {
            $db->exec('DROP TABLE IF EXISTS stg.' . $table);
        }

        $db->exec('DROP TABLE IF EXISTS stg.sorted');
    }

    // --- streets ----------------------------------------------------------------------------------------

    private function streets(BuildState $state): bool
    {
        $db = $this->db($state);

        while (true) {
            $step = $this->getMeta($db, 'streets_step', 'communes');

            if ($step === 'done') {
                $state->phase = BuildState::FTS;
                $this->save($state);

                return true;
            }

            if (!$this->take($step === 'rtree' ? self::RTREE_COST : self::STREETS_COST)) {
                return false;
            }

            if ($step === 'communes') {
                $db->exec('DROP TABLE IF EXISTS stg.commune_tmp');
                $db->exec('CREATE TABLE stg.commune_tmp (city_code TEXT PRIMARY KEY, n INTEGER NOT NULL) WITHOUT ROWID');
                Schema::createStreetTmp($db);
                $db->exec(sprintf('INSERT INTO stg.commune_tmp (city_code, n) SELECT %1$s, SUM(n) FROM stg.street_tmp GROUP BY %1$s', $this->source->communeKeySql('city_code')));
                $db->exec('CREATE INDEX IF NOT EXISTS stg.street_tmp_first ON street_tmp (first_address)');
                $this->setMeta($db, 'streets_step', 'streets');
            } elseif ($step === 'streets') {
                // The rank: (1 + addresses) * (1 + addresses of the commune)^2 orders like
                // ln(1 + addresses) + 2 * ln(1 + addresses of the commune), without math functions:
                // the size of the town weighs most, the length of the street breaks ties between towns.
                $db->beginTransaction();
                $db->exec('DELETE FROM main.street');
                $db->exec(
                    'INSERT INTO main.street (name, postcode, city, city_code, district, first_address, address_count, lat_e6, lon_e6, names, part)
                     SELECT s.name, s.postcode, s.city, s.city_code, s.district, s.first_address, s.n, s.sum_lat / s.n, s.sum_lon / s.n, s.names, NULL
                     FROM stg.street_tmp s JOIN stg.commune_tmp c ON c.city_code = ' . $this->source->communeKeySql('s.city_code') . '
                     ORDER BY (1 + s.n) * (1 + c.n) * (1 + c.n) DESC, s.first_address',
                );
                $this->setMeta($db, 'streets_step', 'index');
                $db->commit();
            } elseif ($step === 'index') {
                Schema::indexes($db);
                $this->setMeta($db, 'rtree_done', 0);
                $this->setMeta($db, 'streets_step', 'rtree');
            } else {
                $from = (int) $this->getMeta($db, 'rtree_done', '0');
                $total = (int) self::scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM main.street');

                if ($from >= $total) {
                    $this->setMeta($db, 'streets_step', 'done');
                } else {
                    $to = min($total, $from + $this->rtreeSlice);
                    $db->beginTransaction();
                    $db->exec(sprintf('DELETE FROM main.street_rtree WHERE id > %d AND id <= %d', $from, $to));
                    $db->exec(sprintf(
                        'INSERT INTO main.street_rtree (id, min_lon, max_lon, min_lat, max_lat)
                         SELECT s.id, MIN(a.lon_e6), MAX(a.lon_e6), MIN(a.lat_e6), MAX(a.lat_e6)
                         FROM main.street s JOIN main.address a ON a.id BETWEEN s.first_address AND s.first_address + s.address_count - 1
                         WHERE s.id > %d AND s.id <= %d GROUP BY s.id',
                        $from,
                        $to,
                    ));
                    $this->setMeta($db, 'rtree_done', $to);
                    $db->commit();
                }
            }

            $this->save($state);
        }
    }

    // --- fts --------------------------------------------------------------------------------------------

    private function fts(BuildState $state): bool
    {
        $db = $this->db($state);
        $tables = $this->source->hasTrigram() ? ['street_fts', 'street_tri'] : ['street_fts'];
        $total = (int) self::scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM main.street');

        while (true) {
            $from = (int) $this->getMeta($db, 'fts_done', '0');

            if ($from >= $total) {
                break;
            }

            if (!$this->take(self::FTS_COST)) {
                return false;
            }

            $this->assertRoom($state);
            $to = min($total, $from + $this->ftsSlice);
            $rows = $db->query(sprintf(
                'SELECT s.id, s.name, s.postcode, s.city, s.district, t.extra FROM main.street s LEFT JOIN stg.street_tmp t ON t.first_address = s.first_address WHERE s.id > %d AND s.id <= %d ORDER BY s.id',
                $from,
                $to,
            ));
            $inserts = [];

            foreach ($tables as $table) {
                $inserts[] = $db->prepare(sprintf('INSERT INTO main.%s (rowid, doc) VALUES (?, ?)', $table));
            }

            $db->beginTransaction();

            foreach ($rows ?: [] as $row) {
                $doc = TextNormalizer::normalize(implode(' ', array_filter([$row['name'], $row['postcode'], $row['city'], $row['district'], $row['extra'] === null ? null : substr((string) $row['extra'], 0, 200)])));

                foreach ($inserts as $insert) {
                    $insert->execute([$row['id'], $doc]);
                }
            }

            $this->setMeta($db, 'fts_done', $to);
            $db->commit();
            $this->save($state);
        }

        foreach ($tables as $table) {
            for ($round = 0; $round < 100_000 && $this->getMeta($db, 'merged_' . $table, '0') !== '1'; ++$round) {
                if (!$this->take(self::MERGE_COST)) {
                    return false;
                }

                $before = (int) self::scalar($db, 'SELECT total_changes()');
                $db->exec(sprintf("INSERT INTO main.%s (%s, rank) VALUES ('merge', 500)", $table, $table));
                $after = (int) self::scalar($db, 'SELECT total_changes()');

                // The INSERT itself counts for one change: more means segments were merged.
                if ($after - $before <= 1) {
                    $this->setMeta($db, 'merged_' . $table, 1);
                }

                $this->save($state);
            }
        }

        $state->phase = BuildState::VERIFY;
        $this->save($state);

        return true;
    }

    // --- verify -----------------------------------------------------------------------------------------

    private function verify(BuildState $state): bool
    {
        if (!$this->take(self::VERIFY_COST)) {
            return false;
        }

        $db = $this->db($state);

        $check = self::scalar($db, 'PRAGMA main.quick_check');

        if ($check !== 'ok') {
            throw new PermanentBuildException('err_integrity');
        }

        $rows = (int) self::scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM main.address');
        $streets = (int) self::scalar($db, 'SELECT COUNT(*) FROM main.street');

        if ($rows === 0 || $streets === 0) {
            throw new PermanentBuildException('err_no_address');
        }

        $existing = $this->existingRows($state->country);

        if ($existing > 0 && $rows < 0.9 * $existing) {
            throw new PermanentBuildException('err_shrunk', [$rows, $existing]);
        }

        $rejected = (int) self::scalar($db, 'SELECT COALESCE(SUM(rejected), 0) FROM stg.progress');
        $oldest = null;

        foreach ($state->versions as $version) {
            $time = $version['last_modified'] === null ? false : self::utc($version['last_modified']);
            $oldest = $time === false ? $oldest : ($oldest === null ? $time : min($oldest, $time));
        }

        $meta = [
            'schema' => (string) Schema::VERSION,
            'builder' => '0.1.0',
            'text_rules' => (string) TextRules::VERSION,
            'country' => $state->country,
            'source' => $this->source->id(),
            'source_url' => $this->source->url(),
            'source_version' => json_encode(['version' => $state->version, 'chunks' => $state->versions], JSON_THROW_ON_ERROR),
            'source_updated_at' => (string) ($oldest ?? time()),
            'licence' => $this->source->licence(),
            'licence_url' => $this->source->licenceUrl(),
            'attribution' => $this->source->attribution(),
            'parts' => '[]',
            'built_at' => (string) time(),
            'build_id' => $state->buildId,
            'rows' => (string) $rows,
            'streets' => (string) $streets,
            'rejected' => (string) $rejected,
            'trigram' => $this->source->hasTrigram() ? '1' : '0',
            'bbox' => json_encode($this->boxes($db), JSON_THROW_ON_ERROR),
        ];

        $db->beginTransaction();

        foreach ($meta as $key => $value) {
            $db->prepare('INSERT OR REPLACE INTO main.meta (key, value) VALUES (?, ?)')->execute([$key, $value]);
        }

        foreach (['build_meta', 'finalized'] as $table) {
            $db->exec('DROP TABLE IF EXISTS main.' . $table);
        }

        $db->commit();
        $db->exec('ANALYZE main');

        $state->phase = BuildState::PUBLISH;
        $this->save($state);

        return true;
    }

    private function existingRows(string $country): int
    {
        $path = self::finalPath($this->dataDir, $country);

        if (!is_file($path)) {
            return 0;
        }

        try {
            $db = new PDO('sqlite:' . $path);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $value = self::scalar($db, "SELECT value FROM meta WHERE key = 'rows'");

            return is_string($value) ? (int) $value : 0;
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Boxes of the territory, one per half-degree cell holding streets, in degrees:
     * France is a metropolis and a handful of far-away departments, and small
     * countries must not claim their neighbours' border towns.
     *
     * @return list<array{float, float, float, float}> min lat, max lat, min lon, max lon
     */
    private function boxes(PDO $db): array
    {
        $cell = self::BBOX_CELL_E6;
        $rows = $db->query(sprintf(
            'SELECT MIN(lat_e6), MAX(lat_e6), MIN(lon_e6), MAX(lon_e6) FROM main.street GROUP BY (lat_e6 + 200000000) / %d, (lon_e6 + 200000000) / %d',
            $cell,
            $cell,
        ));
        $boxes = [];

        foreach ($rows ?: [] as $row) {
            $boxes[] = [(int) $row[0] / 1_000_000, (int) $row[1] / 1_000_000, (int) $row[2] / 1_000_000, (int) $row[3] / 1_000_000];
        }

        return $boxes;
    }

    // --- publish ----------------------------------------------------------------------------------------

    private function publish(BuildState $state): bool
    {
        $this->closeDatabases();
        $built = $this->path($state, '.sqlite');
        $final = self::finalPath($this->dataDir, $state->country);

        if (is_file($built)) {
            $handle = fopen($built, 'rb');

            if ($handle !== false) {
                if (function_exists('fsync')) {
                    fsync($handle);
                }

                fclose($handle);
            }

            if (!rename($built, $final)) {
                throw new TransientBuildException('err_put_in_place');
            }
        } elseif (!is_file($final)) {
            throw new PermanentBuildException('err_db_missing');
        }

        $this->cleanup();
        $state->phase = BuildState::DONE;
        $this->save($state);

        return true;
    }
}
