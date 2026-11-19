# Blitz Bunny Purge

A [Craft CMS](https://craftcms.com) plugin that provides a [Bunny CDN](https://bunny.net) reverse proxy purger for the [Blitz](https://putyourlightson.com/plugins/blitz) caching plugin.

When Blitz invalidates cached content, this purger automatically sends purge requests to the Bunny CDN API to clear the corresponding URLs from the CDN cache.

## Features

- Automatic CDN cache purging when content changes
- Wildcard purging for full site clears (sends `site/*` instead of enumerating every URL)
- Batched URL purging that stays within Bunny's request size and rate limits
- Rate-limited and failed requests are retried through Blitz's queue (or before returning when explicitly run inline)
- Configurable API endpoint and authentication method
- Environment variable support for all settings

## Requirements

- Craft CMS 5.0+
- Blitz 5.0+
- PHP 8.2+

## Installation

Install via Composer:

```bash
composer require jorisnoo/craft-blitz-bunny-purge
```

Then go to **Settings > Plugins** in the Craft control panel and install the plugin.

## Configuration

1. Go to **Settings > Blitz** in the control panel
2. Under **Reverse Proxy Purger**, select **Bunny CDN Purger**
3. Configure the settings:

| Setting | Description | Default |
|---------|-------------|---------|
| **API URL** | The Bunny CDN purge API endpoint | `https://api.bunny.net/purge` |
| **API Key** | Your Bunny CDN API key | — |
| **Authentication Type** | `Access Key` or `Bearer Token` | `Access Key` |
| **Batch Size** | Maximum URLs per JSON request to a custom gateway (1–50); the Bunny Core API accepts one URL per request | `50` |

All settings support environment variables (e.g., `$BUNNY_API_KEY`).

### Environment Variables

Add to your `.env` file:

```env
BUNNY_API_KEY=your-api-key-here
```

Then reference it in the control panel as `$BUNNY_API_KEY`.

### Config File

Alternatively, you can configure the purger via `config/blitz.php` instead of the control panel:

```php
<?php

use craft\helpers\App;
use Noo\CraftBlitzBunnyPurge\BunnyPurger;

return [
    'cachePurgerType' => BunnyPurger::class,
    'cachePurgerSettings' => [
        'apiUrl' => App::env('BUNNY_API_URL') ?: 'https://api.bunny.net/purge',
        'apiKey' => App::env('BUNNY_API_KEY'),
        'authType' => 'access_key', // or 'bearer'
        'batchSize' => 50,
    ],
];
```

## How It Works

### Individual URL Purging

The plugin uses the [documented Bunny Core API request](https://bunny.net/docs/api-reference/core/purge/purge-url): an authenticated POST with one `url` query parameter. Existing escapes in that URL survive the outer request's query decoding.

```
POST https://api.bunny.net/purge?url=https%3A%2F%2Fexample.com%2Fpage-1
```

An explicitly configured gateway receives `POST` with a JSON body such as `{"urls":["https://example.com/page-1","https://example.com/page-2"]}`. The gateway batch size is capped at 50 even if configuration bypasses control-panel validation.

### Site Purging

When an entire site is purged, the purger sends the base URL (`https://example.com`) and a wildcard (`https://example.com/*`) instead of enumerating every URL. Purging all sites sends one deduplicated list across sites. Initial jobs, retries and delayed batches use Blitz's own purger job type, so a full refresh can release pending purges on Blitz's supported Craft queue. Already-running requests are not revoked.

Site purges do not fire Blitz's `beforePurgeCache` and `afterPurgeCache` events, the same as Blitz's Cloudflare purger. `beforePurgeAllCache` and `afterPurgeAllCache` still fire. Sites without URLs or without an absolute base URL are skipped.

### Rate Limits

Bunny rate-limits purges per account with a separate token bucket for each purge type, one token per URL:

| Purge type | Counts as this type | Burst | Refill |
|------------|---------------------|-------|--------|
| Exact | A plain URL | ~120 | 5 per second |
| Wildcard | A URL that contains `*` or ends with `/` | ~20 | 0.5 per second |

Exact and wildcard URLs are kept separate. Compatible gateways receive up to 50 exact or 15 wildcard URLs per request. Each run sends at most 100 exact and 15 wildcard URLs; the rest wait for the buckets to refill. Paths ending in `/`, including paths with a query string, count as wildcard purges, so purging many of them is slow: about 30 per minute.

On `429 Too Many Requests`, the purger honors `Retry-After`, floored to the bucket's refill window and capped at 5 minutes. Server errors and connection failures retry with increasing delays. Only failures of the same batch count toward Blitz's `maxRetryAttempts`. The worker fails on exhausted retries, invalid configuration or a permanent rejection (e.g. `401`). Queued operations schedule remaining work; with `queue: false`, the call waits for pacing and retries and returns only on completion or throws. Use a queue or a long-running CLI process for large purges.

Purge requests time out after 30 seconds (10 seconds to connect).

### Authentication

- **Access Key** (default): Sends an `AccessKey` header
- **Bearer Token**: Sends an `Authorization: Bearer` header

Use HTTPS to protect credentials. The settings connection test never purges content or follows redirects. For the Bunny Core API it calls the authenticated [list pull zones endpoint](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) with `page=1&perPage=5`; the test requires read access to that endpoint and does not prove purge permissions. Custom gateways should support an authenticated, non-mutating `GET` at their configured purge URL and return `2xx` on success. A `405` means the gateway does not support connection testing, not necessarily that its purge credentials are invalid.

## HTTP Generator

Select **Bunny HTTP Generator** in Blitz, or set this in `config/blitz.php`:

```php
'cacheGeneratorType' => \Noo\CraftBlitzBunnyPurge\HttpGenerator::class,
```

It keeps Blitz's HTTP generator settings and sends `User-Agent: Blitz-Cache-Generator` so CDN rules or analytics can identify cache-warming traffic. The header is not authentication.

## License

[MIT](LICENSE.md)

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
