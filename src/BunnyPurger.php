<?php

namespace Noo\CraftBlitzBunnyPurge;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\purgers\BaseCachePurger;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\models\SiteUriModel;
use RuntimeException;
use yii\log\Logger;

class BunnyPurger extends BaseCachePurger
{
    public const DEFAULT_BATCH_SIZE = 500;

    public const CONNECT_TIMEOUT = 10;

    public const TIMEOUT = 30;

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
     * Override to send a wildcard purge instead of enumerating all URIs.
     */
    public function purgeSite(int $siteId, ?callable $setProgressHandler = null, bool $queue = true): void
    {
        $siteUris = [new SiteUriModel(['siteId' => $siteId, 'uri' => ''])];

        if ($queue) {
            CachePurgerHelper::addPurgerJob($siteUris, 'purgeSitesWithProgress');

            return;
        }

        $this->purgeSitesWithProgress($siteUris, $setProgressHandler);
    }

    /**
     * Sends a wildcard purge for each site referenced by the given site URIs.
     * Called directly or from a Blitz driver job queued by `purgeSite()`.
     *
     * @param SiteUriModel[]|array[] $siteUris
     */
    public function purgeSitesWithProgress(array $siteUris, ?callable $setProgressHandler = null): void
    {
        $siteIds = array_values(array_unique(array_map(
            fn (SiteUriModel|array $siteUri): int => (int) ($siteUri instanceof SiteUriModel ? $siteUri->siteId : $siteUri['siteId']),
            $siteUris,
        )));

        $count = 0;
        $total = count($siteIds);
        $label = 'Purging {total} site(s)';

        if (is_callable($setProgressHandler)) {
            call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
        }

        foreach ($siteIds as $siteId) {
            $baseUrl = $this->getSiteBaseUrl($siteId);

            if ($baseUrl !== null) {
                $this->sendPurgeRequest([$baseUrl, "{$baseUrl}/*"]);
            }

            $count++;

            if (is_callable($setProgressHandler)) {
                call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
            }
        }
    }

    public function purgeUrisWithProgress(array $siteUris, ?callable $setProgressHandler = null): void
    {
        $urls = SiteUriHelper::getUrlsFromSiteUris($siteUris);

        $count = 0;
        $total = count($urls);
        $label = 'Purging {total} pages';

        if (is_callable($setProgressHandler)) {
            call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
        }

        $batchSize = max(1, (int) App::parseEnv($this->batchSize));
        $batches = array_chunk($urls, $batchSize);

        foreach ($batches as $batch) {
            $this->sendPurgeRequest($batch);

            $count += count($batch);

            if (is_callable($setProgressHandler)) {
                call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
            }
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
                'instructions' => Craft::t('blitz', 'The maximum number of URLs sent in each purge request.'),
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
     * Returns the absolute base URL of a site without a trailing slash, or
     * null if the site does not exist or its base URL cannot be resolved to
     * an absolute URL (a relative base URL would purge `/*`).
     */
    private function getSiteBaseUrl(int $siteId): ?string
    {
        if (Craft::$app->getSites()->getSiteById($siteId, true) === null) {
            return null;
        }

        $baseUrl = rtrim(UrlHelper::siteUrl('', null, null, $siteId), '/');

        if (parse_url($baseUrl, PHP_URL_HOST) === null) {
            Blitz::$plugin->log(
                'Skipped Bunny CDN site purge for site {siteId}: base URL “{baseUrl}” is not absolute.',
                ['siteId' => $siteId, 'baseUrl' => $baseUrl],
                Logger::LEVEL_WARNING,
            );

            return null;
        }

        return $baseUrl;
    }

    /**
     * Throws on failure so Blitz reports the error and the queue job can retry.
     *
     * @param string[] $urls
     */
    private function sendPurgeRequest(array $urls): void
    {
        $apiKey = App::parseEnv($this->apiKey);
        $apiUrl = App::parseEnv($this->apiUrl);

        if (empty($apiKey) || empty($apiUrl)) {
            throw new RuntimeException('Bunny CDN purge API URL or key not configured.');
        }

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->authHeaders($apiKey),
        );

        Craft::createGuzzleClient()->post($apiUrl, [
            'headers' => $headers,
            'json' => ['urls' => $urls],
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
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
