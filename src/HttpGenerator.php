<?php

namespace Noo\CraftBlitzBunnyPurge;

use Amp\Http\Client\Request;
use putyourlightson\blitz\drivers\generators\HttpGenerator as BlitzHttpGenerator;

class HttpGenerator extends BlitzHttpGenerator
{
    protected function createRequest(string $url): Request
    {
        $request = parent::createRequest($url);

        $request->setHeader('User-Agent', 'Blitz-Cache-Generator');

        return $request;
    }
}
