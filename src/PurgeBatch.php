<?php

namespace Noo\CraftBlitzBunnyPurge;

/**
 * URLs of one purge type, sent in a single Bunny purge request.
 */
final class PurgeBatch
{
    /**
     * @param string[] $urls
     */
    public function __construct(
        public readonly array $urls,
        public readonly bool $wildcard,
    ) {
    }

    public function type(): string
    {
        return $this->wildcard ? 'wildcard' : 'exact';
    }
}
