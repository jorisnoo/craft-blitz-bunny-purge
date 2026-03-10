<?php

namespace Noo\CraftBlitzBunnyPurge;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\Cp;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\purgers\BaseCachePurger;
use putyourlightson\blitz\helpers\SiteUriHelper;
use yii\log\Logger;

class BunnyPurger extends BaseCachePurger
{
    public const API_URL_LIMIT = 100;

    public string $apiUrl = 'https://api.bunny.net/purge';

    public ?string $apiKey = null;

    public string $authType = 'access_key';

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
        ];
    }

    /**
     * Override to send wildcard purge instead of enumerating all URIs.
     */
    public function purgeSite(int $siteId, callable $setProgressHandler = null, bool $queue = true): void
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($site === null) {
            return;
        }

        $baseUrl = rtrim(App::parseEnv($site->getBaseUrl()), '/');

        $this->sendPurgeRequest([$baseUrl, "{$baseUrl}/*"]);
    }

    public function purgeUrisWithProgress(array $siteUris, callable $setProgressHandler = null): void
    {
        $urls = SiteUriHelper::getUrlsFromSiteUris($siteUris);

        $count = 0;
        $total = count($urls);
        $label = 'Purging {total} pages';

        if (is_callable($setProgressHandler)) {
            call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
        }

        $batches = array_chunk($urls, self::API_URL_LIMIT);

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
            ]);
    }

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiUrl', 'apiKey'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['apiKey'], 'required'],
        ];
    }

    /** @param string[] $urls */
    private function sendPurgeRequest(array $urls): bool
    {
        $apiKey = App::parseEnv($this->apiKey);

        if (empty($apiKey)) {
            Blitz::$plugin->log('Bunny CDN purge API key not configured.', [], Logger::LEVEL_WARNING);

            return false;
        }

        $apiUrl = App::parseEnv($this->apiUrl);

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->authHeaders($apiKey),
        );

        $client = Craft::createGuzzleClient();

        try {
            $client->post($apiUrl, [
                'headers' => $headers,
                'json' => ['urls' => $urls],
            ]);

            return true;
        } catch (\Throwable $e) {
            Blitz::$plugin->log($e->getMessage(), [], Logger::LEVEL_ERROR);

            return false;
        }
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
