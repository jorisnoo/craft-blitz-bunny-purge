<?php

use Noo\CraftBlitzBunnyPurge\PurgeBatch;
use Noo\CraftBlitzBunnyPurge\PurgeBatcher;

function exactUrls(int $count, string $host = 'https://example.com'): array
{
    return array_map(fn(int $i) => "{$host}/page-{$i}", range(1, $count));
}

function wildcardUrls(int $count): array
{
    return array_map(fn(int $i) => "https://site-{$i}.example.com/*", range(1, $count));
}

it('classifies wildcard URLs the way Bunny charges them', function(string $url, bool $wildcard) {
    expect(PurgeBatcher::isWildcard($url))->toBe($wildcard);
})->with([
    'plain URL' => ['https://example.com/page', false],
    'host without slash' => ['https://example.com', false],
    'trailing star' => ['https://example.com/*', true],
    'star in the middle' => ['https://example.com/*.jpg', true],
    'trailing slash' => ['https://example.com/page/', true],
    'homepage' => ['https://example.com/', true],
]);

it('clamps the batch size to Bunny\'s 50-URL limit', function(int $configured, int $expected) {
    expect(PurgeBatcher::clampBatchSize($configured))->toBe($expected);
})->with([
    'old default' => [500, 50],
    'just over' => [51, 50],
    'at the limit' => [50, 50],
    'smaller' => [20, 20],
    'zero' => [0, 1],
    'negative' => [-3, 1],
]);

it('never sends more than 50 URLs per request, even with a larger batch size', function() {
    $plan = (new PurgeBatcher(500))->plan(exactUrls(80));

    expect($plan->batches)->toHaveCount(2)
        ->and(count($plan->batches[0]->urls))->toBe(50)
        ->and(count($plan->batches[1]->urls))->toBe(30)
        ->and($plan->deferred)->toBe([]);
});

it('sends at most 100 exact URLs per run and defers the rest for the refill window', function() {
    $urls = exactUrls(300);
    $plan = (new PurgeBatcher())->plan($urls);

    expect($plan->batches)->toHaveCount(2)
        ->and($plan->urlsFrom(0))->toBe(array_slice($urls, 0, 100))
        ->and($plan->deferred)->toBe(array_slice($urls, 100))
        // 100 tokens at 5 per second.
        ->and($plan->deferFor)->toBe(20)
        ->and($plan->urlCount())->toBe(300);
});

it('never mixes exact and wildcard URLs in one request', function() {
    $urls = [
        'https://a.example.com/page',
        'https://b.example.com/*',
        'https://a.example.com/other',
        'https://c.example.com/section/',
    ];

    $plan = (new PurgeBatcher())->plan($urls);

    expect($plan->batches)->toHaveCount(2);

    foreach ($plan->batches as $batch) {
        $types = array_unique(array_map(PurgeBatcher::isWildcard(...), $batch->urls));
        expect($types)->toBe([$batch->wildcard]);
    }

    expect($plan->batches[0])->toEqual(new PurgeBatch(['https://a.example.com/page', 'https://a.example.com/other'], false))
        ->and($plan->batches[1])->toEqual(new PurgeBatch(['https://b.example.com/*', 'https://c.example.com/section/'], true))
        ->and($plan->deferred)->toBe([])
        ->and($plan->deferFor)->toBe(0);
});

it('sends at most 15 wildcard URLs per run and defers the rest for 30 seconds', function() {
    $urls = wildcardUrls(40);
    $plan = (new PurgeBatcher())->plan($urls);

    expect($plan->batches)->toHaveCount(1)
        ->and($plan->batches[0]->wildcard)->toBeTrue()
        ->and($plan->batches[0]->urls)->toBe(array_slice($urls, 0, 15))
        ->and($plan->deferred)->toBe(array_slice($urls, 15))
        // 15 tokens at 0.5 per second.
        ->and($plan->deferFor)->toBe(30);
});

it('counts trailing-slash URLs as wildcard purges', function() {
    $urls = array_map(fn(int $i) => "https://example.com/page-{$i}/", range(1, 20));
    $plan = (new PurgeBatcher())->plan($urls);

    expect($plan->batches)->toHaveCount(1)
        ->and($plan->batches[0]->wildcard)->toBeTrue()
        ->and(count($plan->batches[0]->urls))->toBe(15)
        ->and($plan->deferred)->toHaveCount(5);
});

it('plans a multi-site purge as one exact batch and paced wildcard batches', function() {
    $urls = [];

    foreach (range(1, 30) as $i) {
        $urls[] = "https://site-{$i}.example.com";
        $urls[] = "https://site-{$i}.example.com/*";
    }

    $plan = (new PurgeBatcher())->plan($urls);

    expect($plan->batches)->toHaveCount(2)
        ->and($plan->batches[0]->wildcard)->toBeFalse()
        ->and(count($plan->batches[0]->urls))->toBe(30)
        ->and($plan->batches[1]->wildcard)->toBeTrue()
        ->and(count($plan->batches[1]->urls))->toBe(15)
        ->and($plan->deferred)->toHaveCount(15)
        ->and($plan->deferFor)->toBe(30);
});

it('uses the configured batch size for both types when it is smaller', function() {
    $plan = (new PurgeBatcher(10))->plan([...exactUrls(120), ...wildcardUrls(20)]);

    $exact = array_filter($plan->batches, fn(PurgeBatch $batch) => !$batch->wildcard);
    $wildcard = array_filter($plan->batches, fn(PurgeBatch $batch) => $batch->wildcard);

    expect($exact)->toHaveCount(10)
        ->and($wildcard)->toHaveCount(1)
        ->and(count(reset($wildcard)->urls))->toBe(10)
        ->and($plan->deferred)->toHaveCount(30)
        // Wildcard: 10 tokens at 0.5/s = 20s; exact: 100 tokens at 5/s = 20s.
        ->and($plan->deferFor)->toBe(20);
});

it('drops empty, duplicate and wildcard-covered URLs', function() {
    expect(PurgeBatcher::normalize([
        'https://example.com',
        'https://example.com/*',
        'https://example.com/',
        'https://example.com/de/*',
        'https://example.com/page',
        ' https://other.example.com/page ',
        'https://other.example.com/page',
        '',
        'https://example.com/*',
    ]))->toBe([
        'https://example.com',
        'https://example.com/*',
        'https://other.example.com/page',
    ]);
});

it('returns an empty plan for no URLs', function() {
    $plan = (new PurgeBatcher())->plan([]);

    expect($plan->batches)->toBe([])
        ->and($plan->deferred)->toBe([])
        ->and($plan->deferFor)->toBe(0)
        ->and($plan->urlCount())->toBe(0);
});

it('floors the 429 backoff to the bucket refill window', function(?int $retryAfter, bool $wildcard, int $expected) {
    expect(PurgeBatcher::rateLimitBackoff($retryAfter, $wildcard))->toBe($expected);
})->with([
    'advertised 2s on a drained wildcard bucket' => [2, true, 30],
    'advertised 2s on the exact bucket' => [2, false, 10],
    'longer than the window' => [45, true, 45],
    'no Retry-After' => [null, false, 60],
    'zero' => [0, true, 60],
    'capped' => [1000, false, 300],
]);

it('backs off exponentially after server or connection errors', function(int $attempt, bool $wildcard, int $expected) {
    expect(PurgeBatcher::errorBackoff($attempt, $wildcard))->toBe($expected);
})->with([
    'first exact' => [0, false, 10],
    'second exact' => [1, false, 20],
    'third exact' => [2, false, 40],
    'first wildcard' => [0, true, 30],
    'capped' => [8, false, 300],
    'very large attempt' => [1000, true, 300],
]);

it('reads the wait from a 429 response', function(string $header, string $body, ?int $expected) {
    expect(PurgeBatcher::parseRetryAfter($header, $body, 1_700_000_000))->toBe($expected);
})->with([
    'Retry-After seconds' => ['5', '', 5],
    'Retry-After decimal' => ['2.5', '', 3],
    'Retry-After wins over body' => ['7', '{"type":"prefix","retry_after_seconds":2}', 7],
    'body only' => ['', '{"type":"prefix","retry_after_seconds":2}', 2],
    'HTTP date' => [gmdate('D, d M Y H:i:s \G\M\T', 1_700_000_042), '', 42],
    'HTTP date in the past' => [gmdate('D, d M Y H:i:s \G\M\T', 1_699_999_000), '', 0],
    'nothing' => ['', '', null],
    'non-JSON body' => ['', 'Too Many Requests', null],
]);
