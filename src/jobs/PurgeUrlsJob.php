<?php

namespace Noo\CraftBlitzBunnyPurge\jobs;

use Craft;
use craft\queue\BaseJob;
use Noo\CraftBlitzBunnyPurge\BunnyPurger;
use Noo\CraftBlitzBunnyPurge\PurgeException;
use putyourlightson\blitz\Blitz;
use yii\queue\RetryableJobInterface;

/**
 * Purges a list of URLs through the Bunny CDN purger. Used for site purges and
 * for URLs deferred by rate limiting or a failed request.
 */
class PurgeUrlsJob extends BaseJob implements RetryableJobInterface
{
    /**
     * @var string[]
     */
    public array $urls = [];

    /**
     * @var int Consecutive failed runs before this one.
     */
    public int $attempt = 0;

    public function getTtr(): int
    {
        return Blitz::$plugin->settings->queueJobTtr;
    }

    public function canRetry($attempt, $error): bool
    {
        // The purger already re-queues retryable failures with a backoff.
        if ($error instanceof PurgeException) {
            return false;
        }

        return $attempt < Blitz::$plugin->settings->maxRetryAttempts;
    }

    public function execute($queue): void
    {
        $purger = Blitz::$plugin->cachePurger;

        // The purger was changed after this job was queued.
        if (!$purger instanceof BunnyPurger) {
            return;
        }

        $purger->purgeUrls(
            $this->urls,
            function(int $count, int $total, ?string $label = null) use ($queue) {
                $this->setProgress($queue, $total > 0 ? $count / $total : 0, $label);
            },
            $this->attempt,
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('blitz', 'Purging Blitz cache');
    }
}
