<?php

namespace Programster\GoogleSso\Exceptions;

use Psr\Http\Message\ResponseInterface;


class ExceptionUnexpectedResponse extends \Exception
{
    private ResponseInterface $response;

    public function __construct(ResponseInterface $response)
    {
        parent::__construct("Unexpected error response.");
    }


    public function getResponse() : ResponseInterface { return $this->response;}
}