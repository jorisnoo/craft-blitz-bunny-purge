# Blitz Bunny Purge

A [Craft CMS](https://craftcms.com) plugin that provides a [Bunny CDN](https://bunny.net) reverse proxy purger for the [Blitz](https://putyourlightson.com/plugins/blitz) caching plugin.

When Blitz invalidates cached content, this purger automatically sends purge requests to the Bunny CDN API to clear the corresponding URLs from the CDN cache.

## Features

- Automatic CDN cache purging when content changes
- Wildcard purging for full site clears (sends `site/*` instead of enumerating every URL)
- Batched URL purging (up to 100 URLs per request)
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
    ],
];
```

## How It Works

### Individual URL Purging

When Blitz invalidates specific URLs, the purger sends them to the Bunny CDN API in batches of up to 100 URLs per request:

```
POST https://api.bunny.net/purge
{"urls": ["https://example.com/page-1", "https://example.com/page-2"]}
```

### Site Purging

When an entire site is purged, the purger sends a wildcard request instead of enumerating every URL:

```
POST https://api.bunny.net/purge
{"urls": ["https://example.com", "https://example.com/*"]}
```

### Authentication

- **Access Key** (default): Sends an `AccessKey` header
- **Bearer Token**: Sends an `Authorization: Bearer` header

## License

[MIT](LICENSE.md)
