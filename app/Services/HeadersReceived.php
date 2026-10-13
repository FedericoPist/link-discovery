<?php

namespace App\Services;

use Psr\Http\Message\ResponseInterface;

class HeadersReceived extends \RuntimeException
{
    public function __construct(public ResponseInterface $response)
    {
        parent::__construct('Response headers received.');
    }
}
