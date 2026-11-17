# Blitz Bunny Purge

A [Craft CMS](https://craftcms.com) plugin that provides a [Bunny CDN](https://bunny.net) reverse proxy purger for the [Blitz](https://putyourlightson.com/plugins/blitz) caching plugin.

When Blitz invalidates cached content, this purger automatically sends purge requests to the Bunny CDN API to clear the corresponding URLs from the CDN cache.

## Features

- Automatic CDN cache purging when content changes
- Wildcard purging for full site clears (sends `site/*` instead of enumerating every URL)
- Batched URL purging that stays within Bunny's request size and rate limits
- Rate-limited and failed requests are retried through the Craft queue
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
| **Batch Size** | Maximum URLs sent in each purge request (at most 50; larger values are treated as 50) | `50` |

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

When Blitz invalidates specific URLs, the purger sends them to the Bunny CDN API in batches of up to 50 URLs (the configured batch size, capped at Bunny's limit of 50 per request):

```
POST https://api.bunny.net/purge
{"urls": ["https://example.com/page-1", "https://example.com/page-2"]}
```

### Site Purging

When an entire site is purged, the purger sends a wildcard purge instead of enumerating every URL. Purging all sites sends one deduplicated list for every site, in a queue job (or inline when Blitz is asked not to queue):

```
POST https://api.bunny.net/purge
{"urls": ["https://example.com"]}

POST https://api.bunny.net/purge
{"urls": ["https://example.com/*"]}
```

Site purges do not fire Blitz's `beforePurgeCache` and `afterPurgeCache` events, the same as Blitz's Cloudflare purger. `beforePurgeAllCache` and `afterPurgeAllCache` still fire. Sites without URLs or without an absolute base URL are skipped.

### Rate Limits

Bunny rate-limits purges per account with a separate token bucket for each purge type, one token per URL:

| Purge type | Counts as this type | Burst | Refill |
|------------|---------------------|-------|--------|
| Exact | A plain URL | ~120 | 5 per second |
| Wildcard | A URL that contains `*` or ends with `/` | ~20 | 0.5 per second |

A request that mixes both types is charged to both buckets, so the purger never mixes them. Exact URLs go in batches of up to 50, and wildcard URLs in batches of up to 15. Each run sends at most 100 exact and 15 wildcard URLs; the rest are queued in a delayed job for when the buckets have refilled. URLs ending in `/` (the homepage, or every page when `addTrailingSlashesToUrls` is on) count as wildcard purges, so purging many of them is slow: about 30 per minute.

If Bunny responds with `429 Too Many Requests`, the unsent URLs are queued again after the `Retry-After` wait. That wait is floored to the bucket's refill window (10 seconds for exact, 30 seconds for wildcard), because Bunny's advertised wait can be too short, and capped at 5 minutes. Server errors and connection failures are retried the same way with an increasing delay. After Blitz's `maxRetryAttempts` consecutive failures, or on a response that retrying cannot fix (such as `400` or `401`), the queue job fails and the error is written to the Blitz log.

Purge requests time out after 30 seconds (10 seconds to connect).

### Authentication

- **Access Key** (default): Sends an `AccessKey` header
- **Bearer Token**: Sends an `Authorization: Bearer` header

## License

[MIT](LICENSE.md)

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
