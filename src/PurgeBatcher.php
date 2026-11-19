<?php

namespace Noo\CraftBlitzBunnyPurge;

/**
 * Splits purge URLs into requests that fit Bunny's purge limits and works out
 * how long to wait before sending more. It has no Craft dependencies.
 *
 * Bunny accepts up to 50 URLs per purge request and rate-limits purges with a
 * per-account token bucket per purge type, one token per URL:
 *
 * - exact (a plain URL): burst ~120, refill 5/s
 * - wildcard (contains `*` or ends with `/`): burst ~20, refill 0.5/s
 *
 * A request that mixes both types is charged to both buckets, so each request
 * holds one type only. Each run stays under both bursts; whatever is left is
 * deferred until the bucket has refilled.
 */
final class PurgeBatcher
{
    /** Bunny's maximum number of URLs per purge request. */
    public const MAX_BATCH_SIZE = 50;

    /** Wildcard URLs per request, below the ~20 wildcard burst. */
    public const WILDCARD_BATCH_SIZE = 15;

    /** Exact URLs sent per run, below the ~120 exact burst. */
    public const EXACT_RUN_BUDGET = 100;

    /** Wildcard URLs sent per run, below the ~20 wildcard burst. */
    public const WILDCARD_RUN_BUDGET = 15;

    public const EXACT_REFILL_PER_SECOND = 5;

    public const WILDCARD_REFILL_PER_SECOND = 0.5;

    /** Used on a 429 that does not say how long to wait. */
    public const DEFAULT_RATE_LIMIT_BACKOFF_SECONDS = 60;

    public const MAX_BACKOFF_SECONDS = 300;

    private int $exactBatchSize;

    private int $wildcardBatchSize;

    public function __construct(int $batchSize = self::MAX_BATCH_SIZE)
    {
        $this->exactBatchSize = self::clampBatchSize($batchSize);
        $this->wildcardBatchSize = min(self::WILDCARD_BATCH_SIZE, $this->exactBatchSize);
    }

    public static function clampBatchSize(int $batchSize): int
    {
        return max(1, min(self::MAX_BATCH_SIZE, $batchSize));
    }

    /**
     * Whether Bunny charges the URL to the wildcard ("prefix") bucket.
     */
    public static function isWildcard(string $url): bool
    {
        return str_contains($url, '*') || str_ends_with((string) parse_url($url, PHP_URL_PATH), '/');
    }

    /**
     * Plans one run: the batches to send now, and the URLs to defer.
     *
     * @param string[] $urls
     */
    public function plan(array $urls): PurgePlan
    {
        $urls = self::normalize($urls);

        $exact = array_values(array_filter($urls, fn(string $url) => !self::isWildcard($url)));
        $wildcard = array_values(array_filter($urls, fn(string $url) => self::isWildcard($url)));

        [$exactBatches, $exactDeferred] = $this->split($exact, false);
        [$wildcardBatches, $wildcardDeferred] = $this->split($wildcard, true);

        $deferFor = 0;

        if ($exactDeferred !== []) {
            $deferFor = max($deferFor, self::refillSeconds(false, count($exact) - count($exactDeferred)));
        }

        if ($wildcardDeferred !== []) {
            $deferFor = max($deferFor, self::refillSeconds(true, count($wildcard) - count($wildcardDeferred)));
        }

        return new PurgePlan(
            array_merge($exactBatches, $wildcardBatches),
            array_merge($exactDeferred, $wildcardDeferred),
            $deferFor,
        );
    }

    /**
     * Seconds the bucket of the given type needs to refill the given number of tokens.
     */
    public static function refillSeconds(bool $wildcard, int $tokens): int
    {
        $rate = $wildcard ? self::WILDCARD_REFILL_PER_SECOND : self::EXACT_REFILL_PER_SECOND;

        return (int) ceil(max(0, $tokens) / $rate);
    }

    /**
     * The refill window for a full batch of the given type: the shortest wait
     * after a failure (10s exact, 30s wildcard).
     */
    public static function pacingSeconds(bool $wildcard): int
    {
        return $wildcard
            ? self::refillSeconds(true, self::WILDCARD_BATCH_SIZE)
            : self::refillSeconds(false, self::MAX_BATCH_SIZE);
    }

    /**
     * Wait after a 429. Bunny's advertised wait can be far too short (~2s for
     * a drained wildcard bucket that needs ~30s), so it is floored to the
     * bucket's refill window and capped at MAX_BACKOFF_SECONDS.
     */
    public static function rateLimitBackoff(?int $retryAfter, bool $wildcard): int
    {
        $wait = $retryAfter === null || $retryAfter <= 0
            ? self::DEFAULT_RATE_LIMIT_BACKOFF_SECONDS
            : min($retryAfter, self::MAX_BACKOFF_SECONDS);

        return max($wait, self::pacingSeconds($wildcard));
    }

    /**
     * Wait after a server or connection error: the refill window, doubled for
     * each previous attempt, capped at MAX_BACKOFF_SECONDS.
     */
    public static function errorBackoff(int $attempt, bool $wildcard): int
    {
        $wait = self::pacingSeconds($wildcard) * (2 ** min(max(0, $attempt), 10));

        return min($wait, self::MAX_BACKOFF_SECONDS);
    }

    /**
     * Reads the wait from a 429 response: the `Retry-After` header (seconds or
     * an HTTP date), falling back to `retry_after_seconds` in the JSON body.
     */
    public static function parseRetryAfter(string $header, string $body, ?int $now = null): ?int
    {
        $header = trim($header);

        if ($header !== '') {
            if (is_numeric($header)) {
                return max(0, (int) ceil((float) $header));
            }

            $time = strtotime($header);

            if ($time !== false) {
                return max(0, $time - ($now ?? time()));
            }
        }

        $data = json_decode($body, true);

        if (is_array($data) && isset($data['retry_after_seconds']) && is_numeric($data['retry_after_seconds'])) {
            return (int) ceil((float) $data['retry_after_seconds']);
        }

        return null;
    }

    /**
     * Trims, drops empty and duplicate URLs, and drops URLs already covered by
     * a trailing `*` wildcard in the same list.
     *
     * @param string[] $urls
     * @return string[]
     */
    public static function normalize(array $urls): array
    {
        $urls = array_map(fn($url) => trim((string) $url), $urls);
        $urls = array_values(array_unique(array_filter($urls, fn(string $url) => $url !== '')));

        $prefixes = [];

        foreach ($urls as $url) {
            if (str_ends_with($url, '*')) {
                $prefixes[] = substr($url, 0, -1);
            }
        }

        return array_values(array_filter($urls, function(string $url) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                if ($url !== $prefix . '*' && str_starts_with($url, $prefix)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param string[] $urls
     * @return array{PurgeBatch[], string[]}
     */
    private function split(array $urls, bool $wildcard): array
    {
        $batchSize = $wildcard ? $this->wildcardBatchSize : $this->exactBatchSize;
        $budget = $wildcard ? self::WILDCARD_RUN_BUDGET : self::EXACT_RUN_BUDGET;
        $sendNow = intdiv($budget, $batchSize) * $batchSize;

        $batches = array_map(
            fn(array $chunk) => new PurgeBatch($chunk, $wildcard),
            array_chunk(array_slice($urls, 0, $sendNow), $batchSize),
        );

        return [$batches, array_slice($urls, $sendNow)];
    }
}
