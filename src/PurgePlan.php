<?php

namespace Noo\CraftBlitzBunnyPurge;

/**
 * One purge run: the batches to send now, and the URLs to send in a later run
 * once the rate-limit buckets have refilled.
 */
final class PurgePlan
{
    /**
     * @param PurgeBatch[] $batches
     * @param string[] $deferred
     */
    public function __construct(
        public readonly array $batches,
        public readonly array $deferred,
        public readonly int $deferFor,
    ) {
    }

    /**
     * URLs of the batches from the given index on.
     *
     * @return string[]
     */
    public function urlsFrom(int $index): array
    {
        return array_merge(...array_map(
            fn(PurgeBatch $batch) => $batch->urls,
            array_slice($this->batches, $index),
        ));
    }

    public function urlCount(): int
    {
        return count($this->urlsFrom(0)) + count($this->deferred);
    }
}
