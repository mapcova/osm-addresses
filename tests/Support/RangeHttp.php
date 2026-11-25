<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

use Closure;
use MapCova\OsmAddresses\Support\ByteRange;
use MapCova\OsmAddresses\Support\HttpClientException;
use MapCova\OsmAddresses\Support\SafeHttpClient;
use MapCova\OsmAddresses\Support\SourceChangedException;

/**
 * A {@see SafeHttpClient} serving in-memory files with `206` ranges, validators
 * and `If-Range`, for the builder's tests (no network).
 */
final class RangeHttp implements SafeHttpClient
{
    /** @var array<string, array{body: string, etag: string, last_modified: string, status: int}> */
    private array $files = [];

    /** @var list<string> every request, `HEAD url` or `GET url bytes=a-b` */
    public array $requests = [];

    /** Called before each ranged `GET` is answered, with the URL and the number of `GET`s so far. */
    public ?Closure $onGet = null;

    /** Called before each `HEAD` is answered. */
    public ?Closure $onHead = null;

    /** Answer ranges with a `Content-Range` of another range. */
    public bool $wrongRange = false;

    private int $gets = 0;

    /** @var array<string,int> url => status its `HEAD` is refused with (some servers answer 401) */
    private array $headStatus = [];

    /** @var array<string,true> urls whose server ignores `Range` (a ranged request is answered `200`) */
    private array $noRange = [];

    /** @var array<string,true> urls whose server gives neither ETag nor Last-Modified */
    private array $bare = [];

    /** @var array<string,array<string,string>> url => response headers to add (lower-case names) */
    private array $extraHeaders = [];

    /** @var array<string,string> url => effective URL the answers claim to come from */
    private array $redirects = [];

    /** Make the answers for `$url` come from `$effective`, as after a redirect. */
    public function redirect(string $url, string $effective): self
    {
        $this->redirects[$url] = $effective;
        $this->files[$effective] = $this->files[$url];

        return $this;
    }

    public function serve(string $url, string $body, ?string $etag = null, string $lastModified = 'Wed, 18 Nov 2026 01:50:21 GMT'): self
    {
        $this->files[$url] = ['body' => $body, 'etag' => $etag ?? '"' . md5($body) . '"', 'last_modified' => $lastModified, 'status' => 200];

        return $this;
    }

    /** A response header of this URL (`content-disposition`, as some file names carry the date). */
    public function withHeader(string $url, string $name, string $value): self
    {
        $this->extraHeaders[$url][strtolower($name)] = $value;

        return $this;
    }

    /** The server of this URL gives no validator (no ETag, no Last-Modified), as some servers do. */
    public function withoutValidators(string $url): self
    {
        $this->bare[$url] = true;

        return $this;
    }

    /** `HEAD` of this URL is answered with `$status` (a ranged `GET` still works). */
    public function refuseHead(string $url, int $status = 401): self
    {
        $this->headStatus[$url] = $status;

        return $this;
    }

    /** This server does not serve byte ranges: a ranged `GET` is answered `200`, as the real client reports it. */
    public function withoutRanges(string $url): self
    {
        $this->noRange[$url] = true;

        return $this;
    }

    public function gone(string $url): self
    {
        $this->files[$url] = ['body' => '', 'etag' => '"gone"', 'last_modified' => '', 'status' => 404];

        return $this;
    }

    public function gets(): int
    {
        return $this->gets;
    }

    public function head(string $url): array
    {
        $this->requests[] = 'HEAD ' . $url;

        if ($this->onHead !== null) {
            ($this->onHead)($url);
        }

        $file = $this->files[$url] ?? null;

        if ($file === null) {
            throw HttpClientException::transport($url, 'not served');
        }

        $headers = ['etag' => $file['etag'], 'last-modified' => $file['last_modified'], 'content-length' => (string) strlen($file['body'])];

        $headers += $this->extraHeaders[$url] ?? [];

        if (isset($this->bare[$url])) {
            $headers = ['content-length' => (string) strlen($file['body'])] + ($this->extraHeaders[$url] ?? []);
            $file['etag'] = $file['last_modified'] = null;
        }

        if (isset($this->headStatus[$url])) {
            return ['status' => $this->headStatus[$url], 'headers' => [], 'size' => null, 'etag' => null, 'last_modified' => null, 'url' => $url];
        }

        return [
            'status' => $file['status'],
            'headers' => $headers,
            'size' => strlen($file['body']),
            'etag' => $file['etag'],
            'last_modified' => $file['last_modified'],
            'url' => $this->redirects[$url] ?? $url,
        ];
    }

    public function get(string $url, ?ByteRange $range = null, ?string $ifRange = null, ?callable $sink = null): array
    {
        $this->requests[] = 'GET ' . $url . ($range === null ? '' : ' ' . $range->headerValue());
        ++$this->gets;

        if ($this->onGet !== null) {
            ($this->onGet)($url, $this->gets);
        }

        $file = $this->files[$url] ?? null;

        if ($file === null) {
            throw HttpClientException::transport($url, 'not served');
        }

        if ($file['status'] !== 200) {
            return ['status' => $file['status'], 'headers' => [], 'body' => '', 'url' => $url];
        }

        if ($range !== null && isset($this->noRange[$url])) {
            throw SourceChangedException::forStatus(200, $url);
        }

        if ($range !== null && $ifRange !== null && $ifRange !== $file['etag'] && $ifRange !== $file['last_modified']) {
            throw SourceChangedException::forStatus(200, $url);
        }

        $body = $range === null ? $file['body'] : substr($file['body'], $range->offset, $range->length);

        if ($sink !== null) {
            $sink($body);
            $body = '';
        }

        $headers = (isset($this->bare[$url]) ? [] : ['etag' => $file['etag']]) + ($this->extraHeaders[$url] ?? []);

        if ($range !== null) {
            $headers['content-range'] = sprintf('bytes %d-%d/%d', $range->offset + ($this->wrongRange ? 1 : 0), min($range->end(), strlen($file['body']) - 1), strlen($file['body']));
        }

        return ['status' => $range === null ? 200 : 206, 'headers' => $headers, 'body' => $body, 'url' => $this->redirects[$url] ?? $url];
    }

    public function multiRanges(string $url, array $ranges, callable $onChunk, int $parallel = 6, ?string $ifRange = null): void
    {
        throw new \LogicException('Not used.');
    }
}
