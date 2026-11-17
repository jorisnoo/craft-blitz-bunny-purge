<?php

arch('source code does not contain dd or dump')
    ->expect('Noo\CraftBlitzBunnyPurge')
    ->not->toUse(['dd', 'dump']);

