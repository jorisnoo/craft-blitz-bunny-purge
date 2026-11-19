<?php

namespace Noo\CraftBlitzBunnyPurge;

use Amp\Http\Client\Request;
use Craft;
use putyourlightson\blitz\drivers\generators\HttpGenerator as BlitzHttpGenerator;

class HttpGenerator extends BlitzHttpGenerator
{
    public static function displayName(): string
    {
        return Craft::t('blitz', 'Bunny HTTP Generator');
    }

    protected function createRequest(string $url): Request
    {
        $request = parent::createRequest($url);

        $request->setHeader('User-Agent', 'Blitz-Cache-Generator');

        return $request;
    }
}
