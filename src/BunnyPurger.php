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
use GuzzleHttp\Psr7\Uri;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\purgers\BaseCachePurger;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\jobs\DriverJob;
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

    public ?string $apiUrl = 'https://api.bunny.net/purge';

    public ?string $apiKey = null;

    public ?string $authType = 'access_key';

    public int|string|null $batchSize = self::DEFAULT_BATCH_SIZE;

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

    public function purgeUris(array $siteUris, ?callable $setProgressHandler = null, bool $queue = true): void
    {
        $event = new RefreshCacheEvent(['siteUris' => $siteUris]);
        $this->trigger(self::EVENT_BEFORE_PURGE_CACHE, $event);
        if (!$event->isValid) {
            return;
        }

        $this->purgeUrlsQueued(SiteUriHelper::getUrlsFromSiteUris($event->siteUris), $setProgressHandler, $queue);
        if ($this->hasEventHandlers(self::EVENT_AFTER_PURGE_CACHE)) {
            $this->trigger(self::EVENT_AFTER_PURGE_CACHE, $event);
        }
    }

    public function purgeUrisWithProgress(array $siteUris, ?callable $setProgressHandler = null): void
    {
        $this->purgeUrls(SiteUriHelper::getUrlsFromSiteUris($siteUris), $setProgressHandler);
    }

    /** @param string[] $urls */
    public function purgeUrls(array $urls, ?callable $setProgressHandler = null): void
    {
        $this->runBatches($urls, $setProgressHandler, false);
    }

    /** Called by Blitz's cancellable purger DriverJob. */
    public function resumeBunnyPurge(array $work, callable $setProgressHandler): void
    {
        $this->runBatches($work['urls'], $setProgressHandler, true, $work['attempt'], $work['tail']);
    }

    private function runBatches(array $urls, ?callable $progress, bool $queued, int $attempt = 0, array $tail = []): void
    {
        [$endpoint, $headers] = $this->connection();
        $client = $this->createClient();
        // Bunny's Core API takes one query parameter. Compatible custom gateways
        // accept a JSON batch. Both share the same per-URL token budgets.
        $native = strtolower($endpoint->getHost()) === 'api.bunny.net';
        $batcher = new PurgeBatcher($native ? 1 : (int) App::parseEnv($this->batchSize));
        $plan = $batcher->plan($urls);
        $count = 0;
        $total = $plan->urlCount() + count($tail);
        $label = Craft::t('blitz', 'Purging {total} pages', ['total' => $total]);
        if ($progress !== null) {
            $progress($count, $total, $label);
        }

        while (true) {
            foreach ($plan->batches as $index => $batch) {
                while (true) {
                    try {
                        $options = ['headers' => $headers, 'allow_redirects' => false, 'http_errors' => true];
                        if ($native) {
                            $uri = Uri::withQueryValue($endpoint, 'url', $batch->urls[0]);
                        } else {
                            $uri = $endpoint;
                            $options['json'] = ['urls' => $batch->urls];
                        }
                        $response = $client->request('POST', $uri, $options);
                        if (intdiv($response->getStatusCode(), 100) !== 2) {
                            throw new PurgeException('Bunny CDN purge returned HTTP ' . $response->getStatusCode());
                        }
                        break;
                    } catch (GuzzleException $exception) {
                        $delay = $this->retryDelay($exception, $batch, $attempt);
                        ++$attempt;
                        if ($queued) {
                            $remaining = array_merge($plan->urlsFrom($index + 1), $plan->deferred, $tail);
                            $this->queuePurgeJob($batch->urls, $delay, $attempt, $remaining);
                            return;
                        }
                        $this->waitForRefill($delay);
                    }
                }

                $attempt = 0;
                $count += count($batch->urls);
                if ($progress !== null) {
                    $progress($count, $total, $label);
                }
            }

            $remaining = array_merge($plan->deferred, $tail);
            if (!$remaining) {
                return;
            }
            // A queued retry carries its original batch separately. Start the
            // unattempted tail on a fresh budget once both buckets can refill.
            $delay = $tail ? max($plan->deferFor, PurgeBatcher::pacingSeconds(true)) : $plan->deferFor;
            if ($queued) {
                $this->queuePurgeJob($remaining, $delay);
                return;
            }
            $this->waitForRefill($delay);
            $plan = $batcher->plan($remaining);
            $tail = [];
        }
    }

    protected function waitForRefill(int $seconds): void
    {
        while (($seconds = sleep($seconds)) > 0) {
            // Resume the unslept interval if the process received a signal.
        }
    }

    public function test(): bool
    {
        try {
            [$endpoint, $headers] = $this->connection();
            $native = strtolower($endpoint->getHost()) === 'api.bunny.net';
            $uri = $native ? $endpoint->withPath('/pullzone')->withQuery('page=1&perPage=5') : $endpoint;
            $response = $this->createClient()->request('GET', $uri, [
                'headers' => $headers,
                'timeout' => 5,
                'connect_timeout' => 3,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            if (intdiv($response->getStatusCode(), 100) !== 2) {
                throw new PurgeException('Connection test returned HTTP ' . $response->getStatusCode() . '.');
            }
        } catch (PurgeException $e) {
            $this->addError('apiUrl', $e->getMessage());
            return false;
        } catch (GuzzleException) {
            $this->addError('apiUrl', 'Could not reach the CDN connection-test endpoint.');
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
                'instructions' => Craft::t('blitz', 'Custom gateways accept batches of up to 50 URLs. The Bunny Core API accepts one URL per request.'),
                'id' => 'batchSize',
                'name' => 'batchSize',
                'value' => $this->batchSize,
                'type' => 'number',
                'min' => 1,
                'max' => self::MAX_BATCH_SIZE,
                'required' => true,
                'suggestEnvVars' => true,
            ]);
    }

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiUrl', 'apiKey', 'authType', 'batchSize'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['apiKey', 'apiUrl', 'authType', 'batchSize'], 'required'],
            [['apiUrl'], 'url'],
            [['authType'], 'in', 'range' => ['access_key', 'bearer']],
            [['batchSize'], 'integer', 'min' => 1, 'max' => self::MAX_BATCH_SIZE],
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
    private function queuePurgeJob(array $urls, int $delay = 0, int $attempt = 0, array $tail = []): void
    {
        Queue::push(
            job: new DriverJob([
                'driverId' => CachePurgerHelper::DRIVER_ID,
                'driverMethod' => 'resumeBunnyPurge',
                'siteUris' => ['urls' => $urls, 'attempt' => $attempt, 'tail' => $tail],
                'description' => Craft::t('blitz', 'Purging Blitz cache'),
            ]),
            priority: Blitz::$plugin->settings->driverJobPriority,
            delay: $delay > 0 ? $delay : null,
            queue: Blitz::$plugin->queue,
        );
    }

    private function retryDelay(GuzzleException $exception, PurgeBatch $batch, int $attempt): int
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
            $reason = $status === null ? 'connection failed' : "server error {$status}";
        } else {
            throw new PurgeException("Bunny CDN purge failed with status {$status}.", $status, $exception);
        }

        $maxAttempts = Blitz::$plugin->settings->maxRetryAttempts;

        if ($attempt + 1 >= $maxAttempts) {
            throw new PurgeException("Bunny CDN purge gave up after {$maxAttempts} attempts, last {$reason}.", 0, $exception);
        }

        Blitz::$plugin->log('Bunny CDN purge {reason}; retrying the batch in {seconds}s.', [
            'reason' => $reason,
            'seconds' => $delay,
        ], Logger::LEVEL_WARNING);

        return $delay;
    }

    /** @return array{Uri, array<string, string>} */
    private function connection(): array
    {
        $key = App::parseEnv($this->apiKey);
        if (!is_string($key) || trim($key) === '') {
            throw new PurgeException('Bunny CDN purge API key not configured.');
        }
        $url = App::parseEnv($this->apiUrl);
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new PurgeException('An absolute HTTP or HTTPS purge API URL is required.');
        }
        $endpoint = new Uri($url);
        if (!in_array($endpoint->getScheme(), ['https', 'http'], true) || $endpoint->getUserInfo() !== '' || $endpoint->getFragment() !== '') {
            throw new PurgeException('The purge API URL must use HTTP or HTTPS without credentials or a fragment.');
        }
        if (strtolower($endpoint->getHost()) === 'api.bunny.net' && ($endpoint->getScheme() !== 'https' || $endpoint->getPath() !== '/purge')) {
            throw new PurgeException('The Bunny Core API purge endpoint is https://api.bunny.net/purge.');
        }
        $headers = match (App::parseEnv($this->authType)) {
            'bearer' => ['Authorization' => "Bearer {$key}"],
            'access_key' => ['AccessKey' => $key],
            default => throw new PurgeException('Authentication type must be access_key or bearer.'),
        };
        return [$endpoint, $headers];
    }
}
