<?php

namespace Noo\CraftBlitzBunnyPurge;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\models\Site;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Noo\CraftBlitzBunnyPurge\jobs\PurgeUrlsJob;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\purgers\BaseCachePurger;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\helpers\SiteUriHelper;
use yii\log\Logger;

class BunnyPurger extends BaseCachePurger
{
    /**
     * Bunny accepts at most 50 URLs per purge request. Larger batch sizes are
     * treated as 50.
     */
    public const DEFAULT_BATCH_SIZE = PurgeBatcher::MAX_BATCH_SIZE;

    public const MAX_BATCH_SIZE = PurgeBatcher::MAX_BATCH_SIZE;

    public const CONNECT_TIMEOUT = 10;

    public const REQUEST_TIMEOUT = 30;

    /** @deprecated Use $batchSize instead. */
    public const API_URL_LIMIT = self::DEFAULT_BATCH_SIZE;

    public string $apiUrl = 'https://api.bunny.net/purge';

    public ?string $apiKey = null;

    public string $authType = 'access_key';

    public int|string $batchSize = self::DEFAULT_BATCH_SIZE;

    public static function displayName(): string
    {
        return Craft::t('blitz', 'Bunny CDN Purger');
    }

    public function attributeLabels(): array
    {
        return [
            'apiUrl' => Craft::t('blitz', 'API URL'),
            'apiKey' => Craft::t('blitz', 'API Key'),
            'authType' => Craft::t('blitz', 'Authentication Type'),
            'batchSize' => Craft::t('blitz', 'Batch Size'),
        ];
    }

    /**
     * Sends wildcard purges for every site in one deduplicated list, instead
     * of one request per site, so the wildcard batches can be paced.
     *
     * When `$queue` is true, the purge runs in a queue job.
     */
    public function purgeAll(?callable $setProgressHandler = null, bool $queue = true): void
    {
        $event = new RefreshCacheEvent();
        $this->trigger(self::EVENT_BEFORE_PURGE_ALL_CACHE, $event);

        if (!$event->isValid) {
            return;
        }

        $urls = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            array_push($urls, ...$this->getSitePurgeUrls($site));
        }

        $this->purgeUrlsQueued($urls, $setProgressHandler, $queue);

        if ($this->hasEventHandlers(self::EVENT_AFTER_PURGE_ALL_CACHE)) {
            $this->trigger(self::EVENT_AFTER_PURGE_ALL_CACHE, $event);
        }
    }

    /**
     * Sends a wildcard purge instead of enumerating all URIs.
     *
     * When `$queue` is true, the purge runs in a queue job. Like Blitz's
     * Cloudflare purger, this does not fire the purge cache events.
     */
    public function purgeSite(int $siteId, ?callable $setProgressHandler = null, bool $queue = true): void
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($site === null) {
            return;
        }

        $this->purgeUrlsQueued($this->getSitePurgeUrls($site), $setProgressHandler, $queue);
    }

    public function purgeUrisWithProgress(array $siteUris, ?callable $setProgressHandler = null): void
    {
        $this->purgeUrls(SiteUriHelper::getUrlsFromSiteUris($siteUris), $setProgressHandler);
    }

    /**
     * Purges URLs in batches that fit Bunny's rate limits.
     *
     * Exact and wildcard URLs go in separate requests. URLs beyond what the
     * rate-limit buckets allow in one run, and URLs whose request was rate
     * limited or failed with a server or connection error, are re-queued in a
     * delayed job. A non-retryable response (such as 400 or 401), or retries
     * used up, throws a [[PurgeException]] so the queue job fails.
     *
     * @param string[] $urls
     * @param int $attempt Consecutive failed runs before this one.
     * @throws PurgeException
     */
    public function purgeUrls(array $urls, ?callable $setProgressHandler = null, int $attempt = 0): void
    {
        $apiKey = (string) App::parseEnv($this->apiKey);

        if ($apiKey === '') {
            Blitz::$plugin->log('Bunny CDN purge API key not configured.', [], Logger::LEVEL_WARNING);

            return;
        }

        $plan = (new PurgeBatcher((int) App::parseEnv($this->batchSize)))->plan($urls);

        $count = 0;
        $total = $plan->urlCount();
        $label = 'Purging {total} pages';

        if (is_callable($setProgressHandler)) {
            call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
        }

        $client = $this->createClient();

        foreach ($plan->batches as $index => $batch) {
            try {
                $this->sendPurgeRequest($client, $apiKey, $batch->urls);
            } catch (GuzzleException $exception) {
                $remaining = array_merge($plan->urlsFrom($index), $plan->deferred);
                $this->handleFailedRequest($exception, $batch, $remaining, $attempt);

                return;
            }

            $count += count($batch->urls);

            if (is_callable($setProgressHandler)) {
                call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
            }
        }

        if ($plan->deferred !== []) {
            Blitz::$plugin->log('Deferred purging {count} URLs from Bunny CDN for {seconds}s to stay within the rate limit.', [
                'count' => count($plan->deferred),
                'seconds' => $plan->deferFor,
            ]);

            $this->queuePurgeJob($plan->deferred, $plan->deferFor);
        }
    }

    public function test(): bool
    {
        $apiKey = App::parseEnv($this->apiKey);

        if (empty($apiKey)) {
            $this->addError('apiKey', Craft::t('blitz', 'An API key is required.'));

            return false;
        }

        return true;
    }

    public function getSettingsHtml(): ?string
    {
        return
            Cp::autosuggestFieldHtml([
                'label' => Craft::t('blitz', 'API URL'),
                'instructions' => Craft::t('blitz', 'The purge API endpoint.'),
                'id' => 'apiUrl',
                'name' => 'apiUrl',
                'value' => $this->apiUrl,
                'suggestEnvVars' => true,
            ]) .
            Cp::autosuggestFieldHtml([
                'label' => Craft::t('blitz', 'API Key'),
                'instructions' => Craft::t('blitz', 'The API key for authenticating with the CDN.'),
                'id' => 'apiKey',
                'name' => 'apiKey',
                'value' => $this->apiKey,
                'required' => true,
                'suggestEnvVars' => true,
            ]) .
            Cp::selectFieldHtml([
                'label' => Craft::t('blitz', 'Authentication Type'),
                'instructions' => Craft::t('blitz', 'The header format used for authentication.'),
                'id' => 'authType',
                'name' => 'authType',
                'value' => $this->authType,
                'options' => [
                    ['value' => 'access_key', 'label' => 'Access Key'],
                    ['value' => 'bearer', 'label' => 'Bearer Token'],
                ],
            ]) .
            Cp::autosuggestFieldHtml([
                'label' => Craft::t('blitz', 'Batch Size'),
                'instructions' => Craft::t('blitz', 'The maximum number of URLs sent in each purge request. Bunny accepts at most 50; larger values are treated as 50.'),
                'id' => 'batchSize',
                'name' => 'batchSize',
                'value' => $this->batchSize,
                'type' => 'number',
                'min' => 1,
                'required' => true,
                'suggestEnvVars' => true,
            ]);
    }

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiUrl', 'apiKey', 'batchSize'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['apiKey'], 'required'],
            [['batchSize'], 'required'],
            [['batchSize'], 'integer', 'min' => 1],
        ];
    }

    /**
     * Returns the Guzzle client used for purge requests.
     */
    protected function createClient(): ClientInterface
    {
        return Craft::createGuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::REQUEST_TIMEOUT,
            // Failures must throw, even if config/guzzle.php turns this off.
            'http_errors' => true,
        ]);
    }

    /**
     * @return string[]
     */
    private function getSitePurgeUrls(Site $site): array
    {
        $baseUrl = $site->getBaseUrl();

        if (!$site->hasUrls || empty($baseUrl) || !UrlHelper::isAbsoluteUrl($baseUrl)) {
            return [];
        }

        $baseUrl = rtrim($baseUrl, '/');

        return [$baseUrl, "{$baseUrl}/*"];
    }

    /**
     * @param string[] $urls
     */
    private function purgeUrlsQueued(array $urls, ?callable $setProgressHandler, bool $queue): void
    {
        if ($urls === []) {
            return;
        }

        if ($queue) {
            $this->queuePurgeJob($urls);

            return;
        }

        $this->purgeUrls($urls, $setProgressHandler);
    }

    /**
     * @param string[] $urls
     */
    private function queuePurgeJob(array $urls, int $delay = 0, int $attempt = 0): void
    {
        Queue::push(
            job: new PurgeUrlsJob([
                'urls' => array_values($urls),
                'attempt' => $attempt,
            ]),
            priority: Blitz::$plugin->settings->driverJobPriority,
            delay: $delay > 0 ? $delay : null,
            queue: Blitz::$plugin->queue,
        );
    }

    /**
     * Re-queues the unsent URLs after a retryable failure, or throws.
     *
     * @param string[] $remaining
     * @throws PurgeException
     */
    private function handleFailedRequest(GuzzleException $exception, PurgeBatch $batch, array $remaining, int $attempt): void
    {
        $response = $exception instanceof RequestException ? $exception->getResponse() : null;
        $status = $response?->getStatusCode();

        if ($status === 429) {
            $retryAfter = PurgeBatcher::parseRetryAfter(
                $response->getHeaderLine('Retry-After'),
                (string) $response->getBody(),
            );
            $delay = PurgeBatcher::rateLimitBackoff($retryAfter, $batch->wildcard);
            $reason = "rate limited ({$batch->type()})";
        } elseif ($status === null || $status >= 500) {
            $delay = PurgeBatcher::errorBackoff($attempt, $batch->wildcard);
            $reason = $status === null ? 'request failed: ' . $exception->getMessage() : "server error {$status}";
        } else {
            $message = "Bunny CDN purge failed with status {$status}: " . mb_substr((string) $response->getBody(), 0, 500);
            Blitz::$plugin->log('{message}', ['message' => $message], Logger::LEVEL_ERROR);

            throw new PurgeException($message, $status, $exception);
        }

        $maxAttempts = Blitz::$plugin->settings->maxRetryAttempts;

        if ($attempt + 1 >= $maxAttempts) {
            $message = "Bunny CDN purge gave up on " . count($remaining) . " URLs after {$maxAttempts} attempts, last {$reason}.";
            Blitz::$plugin->log('{message}', ['message' => $message], Logger::LEVEL_ERROR);

            throw new PurgeException($message, 0, $exception);
        }

        Blitz::$plugin->log('Bunny CDN purge {reason}; retrying {count} URLs in {seconds}s.', [
            'reason' => $reason,
            'count' => count($remaining),
            'seconds' => $delay,
        ], Logger::LEVEL_WARNING);

        $this->queuePurgeJob($remaining, $delay, $attempt + 1);
    }

    /**
     * @param string[] $urls
     * @throws GuzzleException
     */
    private function sendPurgeRequest(ClientInterface $client, string $apiKey, array $urls): void
    {
        $client->request('POST', App::parseEnv($this->apiUrl), [
            'headers' => array_merge(
                ['Content-Type' => 'application/json'],
                $this->authHeaders($apiKey),
            ),
            'json' => ['urls' => $urls],
        ]);
    }

    /** @return array<string, string> */
    private function authHeaders(string $apiKey): array
    {
        return match ($this->authType) {
            'bearer' => ['Authorization' => "Bearer {$apiKey}"],
            default => ['AccessKey' => $apiKey],
        };
    }
}
