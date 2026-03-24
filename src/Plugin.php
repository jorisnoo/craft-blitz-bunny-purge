<?php

namespace Noo\CraftBlitzBunnyPurge;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use craft\base\Event;

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
    }
}
