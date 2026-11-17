<?php

namespace Noo\CraftBlitzBunnyPurge;

use yii\base\Exception;

/**
 * A purge that retrying will not fix: a non-retryable response from Bunny, or
 * retries used up.
 */
class PurgeException extends Exception
{
    public function getName(): string
    {
        return 'Bunny CDN Purge Exception';
    }
}
