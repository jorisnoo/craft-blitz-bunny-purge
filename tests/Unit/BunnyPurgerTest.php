<?php

use craft\events\RegisterComponentTypesEvent;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Noo\CraftBlitzBunnyPurge\BunnyPurger;
use Noo\CraftBlitzBunnyPurge\HttpGenerator;
use Noo\CraftBlitzBunnyPurge\Plugin;
use Noo\CraftBlitzBunnyPurge\PurgeBatcher;
use Noo\CraftBlitzBunnyPurge\PurgeException;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\CacheGeneratorHelper;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use putyourlightson\blitz\jobs\DriverJob;
use putyourlightson\blitz\models\SettingsModel;
use putyourlightson\blitz\models\SiteUriModel;
use yii\base\Event;
use yii\db\Connection;
use yii\queue\ExecEvent;
use yii\queue\sync\Queue;

beforeEach(function() {
    $this->http = new MockHandler();
    $stack = HandlerStack::create($this->http);
    $this->requests = [];
    $stack->push(Middleware::history($this->requests));
    $this->purger = new class extends BunnyPurger {
        public ClientInterface $client;
        public array $waits = [];
        protected function createClient(): ClientInterface { return $this->client; }
        protected function waitForRefill(int $seconds): void { $this->waits[] = $seconds; }
    };
    $this->purger->client = new Client(['handler' => $stack]);
    $this->purger->apiKey = 'test-only';
    $this->purger->apiUrl = 'https://gateway.example/purge';
    $this->purger->batchSize = 1;

    Blitz::$plugin = $this->getMockBuilder(Blitz::class)->disableOriginalConstructor()->onlyMethods(['log', 'getSettings'])->getMock();
    Blitz::$plugin->method('getSettings')->willReturn(new SettingsModel(['maxRetryAttempts' => 2]));
    Blitz::$plugin->queue = new Queue();
    $this->jobs = [];
    Blitz::$plugin->queue->on(Queue::EVENT_AFTER_PUSH, function($event) {
        $this->jobs[] = $event;
    });
});

it('sends the documented native Core API request without changing the URL cache key', function() {
    $this->purger->apiUrl = 'https://api.bunny.net/purge';
    $urls = ['https://example.test/a?x=1&x=2', 'https://example.test/b/'];
    $this->http->append(new Response(200), new Response(200));
    $this->purger->purgeUrls($urls);
    foreach ($this->requests as $index => $transaction) {
        $request = $transaction['request'];
        parse_str($request->getUri()->getQuery(), $query);
        expect($request->getMethod())->toBe('POST')->and($query)->toBe(['url' => $urls[$index]])
            ->and($request->getHeaderLine('AccessKey'))->toBe('test-only');
    }
    expect($this->requests)->toHaveCount(2);
});

it('checks credentials with a bounded read-only request and rejects failed checks', function(int $status, bool $valid) {
    $this->purger->apiUrl = 'https://api.bunny.net/purge';
    $this->http->append(new Response($status));
    expect($this->purger->test())->toBe($valid)->and($this->purger->hasErrors())->toBe(!$valid);
    $request = $this->requests[0]['request'];
    expect($request->getMethod())->toBe('GET')->and((string) $request->getUri())->toBe('https://api.bunny.net/pullzone?page=1&perPage=5')
        ->and($request->getHeaderLine('AccessKey'))->toBe('test-only')
        ->and($this->requests[0]['options']['timeout'])->toBe(5)->and($this->requests[0]['options']['allow_redirects'])->toBeFalse();
})->with([[200, true], [401, false], [403, false], [302, false], [503, false]]);

it('checks a custom gateway on its configured URL using the configured authentication', function() {
    $this->purger->authType = 'bearer';
    $this->http->append(new Response(204));
    expect($this->purger->test())->toBeTrue();
    $request = $this->requests[0]['request'];
    expect((string) $request->getUri())->toBe($this->purger->apiUrl)
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test-only');
});

it('fails an unconfigured worker before dispatching or advancing progress', function(string $attribute, ?string $value) {
    $this->purger->$attribute = $value;
    $work = ['urls' => ['https://example.test/a'], 'attempt' => 0, 'tail' => []];
    $progress = fn() => $this->fail('Invalid configuration must not report progress');
    expect(fn() => $this->purger->resumeBunnyPurge($work, $progress))->toThrow(PurgeException::class)
        ->and($this->purger->test())->toBeFalse()->and($this->requests)->toBeEmpty()->and($this->jobs)->toBeEmpty();
})->with([['apiKey', null], ['apiUrl', '$UNSET_BUNNY_PURGE_TEST_URL'], ['apiUrl', 'relative'], ['authType', 'Bearer']]);

it('finishes explicit inline work, including deferred URLs and a retry on each batch', function() {
    $urls = array_map(fn($i) => "https://example.test/{$i}/?page=1", range(1, 16));
    $siteUris = array_map(fn($url) => new class(['uri' => $url]) extends SiteUriModel {
        public function getUrl(array $params = []): string { return $this->uri; }
    }, $urls);
    $this->purger->batchSize = 50;
    $this->http->append(new Response(429, ['Retry-After' => '45']), new Response(200), new Response(503), new Response(200));
    $progress = [];
    $this->purger->purgeUris($siteUris, function($count, $total) use (&$progress) { $progress[] = [$count, $total]; }, false);
    $bodies = array_map(fn($tx) => json_decode((string) $tx['request']->getBody(), true)['urls'], $this->requests);
    expect($bodies)->toBe([array_slice($urls, 0, 15), array_slice($urls, 0, 15), [$urls[15]], [$urls[15]]])
        ->and($progress)->toBe([[0, 16], [15, 16], [16, 16]])->and($this->purger->waits)->toBe([45, 30, 30])->and($this->jobs)->toBeEmpty();
});

it('does not transfer a queued retry attempt to an unattempted batch', function() {
    $urls = ['https://example.test/a', 'https://example.test/b'];
    $this->http->append(new Response(503), new Response(200), new Response(503), new Response(200));
    $run = fn($work) => $this->purger->resumeBunnyPurge($work, static function() {});
    $run(['urls' => $urls, 'attempt' => 0, 'tail' => []]);
    $run($this->jobs[0]->job->siteUris);
    $run($this->jobs[1]->job->siteUris);
    $run($this->jobs[2]->job->siteUris);
    expect(array_map(fn($e) => $e->job->siteUris, $this->jobs))->toBe([
        ['urls' => [$urls[0]], 'attempt' => 1, 'tail' => [$urls[1]]],
        ['urls' => [$urls[1]], 'attempt' => 0, 'tail' => []],
        ['urls' => [$urls[1]], 'attempt' => 1, 'tail' => []],
    ])->and($this->requests)->toHaveCount(4)->and($this->purger->waits)->toBeEmpty();
});

it('stops on permanent and exhausted failures without reporting completion', function(int $status, int $attempt) {
    $this->http->append(new Response($status));
    $counts = [];
    $run = function() use ($attempt, &$counts) {
        $this->purger->resumeBunnyPurge(['urls' => ['https://example.test/page'], 'attempt' => $attempt, 'tail' => []],
            function($count) use (&$counts) { $counts[] = $count; });
    };
    expect($run)->toThrow(PurgeException::class)->and($counts)->toBe([0])->and($this->jobs)->toBeEmpty();
})->with([[401, 0], [429, 1], [503, 1]]);

it('lets Blitz release pending continuations but not reserved or unrelated jobs', function() {
    $this->http->append(new Response(429));
    $this->purger->resumeBunnyPurge(['urls' => ['https://example.test/a'], 'attempt' => 0, 'tail' => []], static function() {});
    $job = $this->jobs[0]->job;
    expect($job)->toBeInstanceOf(DriverJob::class)->and($job->driverMethod)->toBe('resumeBunnyPurge');
    $db = new Connection(['dsn' => 'sqlite::memory:', 'schemaCache' => null]);
    $db->createCommand()->createTable('queue', ['id' => 'integer','job' => 'text', 'dateReserved' => 'integer'])->execute();
    $other = clone $job;
    $other->driverId = 'cacheGenerator';
    $db->createCommand()->batchInsert('queue', ['id','job','dateReserved'], [
        [1, serialize($job), null], [2, serialize($job), 123], [3, serialize($other), null],
    ])->execute();
    $queue = $this->getMockBuilder(\craft\queue\Queue::class)->disableOriginalConstructor()->onlyMethods(['release'])->getMock();
    $queue->tableName = 'queue';
    $queue->expects($this->once())->method('release')->with('1');
    $app = $this->getMockBuilder(\craft\console\Application::class)->disableOriginalConstructor()->onlyMethods(['getQueue', 'getDb'])->getMock();
    $app->method('getQueue')->willReturn($queue);
    $app->method('getDb')->willReturn($db);
    Craft::$app = $app;
    try {
        CachePurgerHelper::releasePurgerJobs();
    } finally {
        Craft::$app = null;
    }
});

it('registers the distinct generator and prevents Blitz from retrying a terminal Bunny failure', function() {
    (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor()->init();
    try {
        $types = new RegisterComponentTypesEvent();
        Event::trigger(CacheGeneratorHelper::class, CacheGeneratorHelper::EVENT_REGISTER_GENERATOR_TYPES, $types);
        expect($types->types)->toContain(HttpGenerator::class)->and(HttpGenerator::displayName())->toBe('Bunny HTTP Generator');
        $event = new ExecEvent(['job' => new DriverJob(['driverId' => CachePurgerHelper::DRIVER_ID]), 'retry' => true, 'error' => new PurgeException('terminal')]);
        Blitz::$plugin->queue->trigger(Queue::EVENT_AFTER_ERROR, $event);
        expect($event->retry)->toBeFalse();
    } finally {
        Event::off(CacheGeneratorHelper::class, CacheGeneratorHelper::EVENT_REGISTER_GENERATOR_TYPES);
        Event::off(CachePurgerHelper::class, CachePurgerHelper::EVENT_REGISTER_PURGER_TYPES);
        Event::off(\yii\queue\Queue::class, Queue::EVENT_AFTER_ERROR);
    }
});
