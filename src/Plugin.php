<?php

namespace Noo\CraftBlitzBunnyPurge;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use putyourlightson\blitz\helpers\CacheGeneratorHelper;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use craft\base\Event;
use putyourlightson\blitz\jobs\DriverJob;
use yii\queue\ExecEvent;
use yii\queue\Queue;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        Event::on(
            CachePurgerHelper::class,
            CachePurgerHelper::EVENT_REGISTER_PURGER_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = BunnyPurger::class;
            }
        );

        Event::on(CacheGeneratorHelper::class, CacheGeneratorHelper::EVENT_REGISTER_GENERATOR_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = HttpGenerator::class;
            }
        );

        // Blitz cancels native purger jobs on refresh. Its default retry policy
        // must not replay our terminal failures, including exhausted retries.
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, static function(ExecEvent $event) {
            if ($event->job instanceof DriverJob && $event->job->driverId === CachePurgerHelper::DRIVER_ID
                && $event->error instanceof PurgeException) {
                $event->retry = false;
            }
        });
    }
}
