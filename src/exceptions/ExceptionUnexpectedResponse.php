<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

use Psr\Http\Message\ResponseInterface;


class ExceptionUnexpectedResponse extends \Exception
{
    private readonly ResponseInterface $m_response;


    /**
     * Create an exception for when Google responded with something we didn't expect, such as an error status, or a
     * body that isn't the JSON we needed.
     *
     * @param ResponseInterface $response - the response that Google sent.
     *
     * @param string $message - optionally, a description of what was wrong with the response.
     */
    public function __construct(ResponseInterface $response, string $message = "Unexpected error response.")
    {
        $this->m_response = $response;
        parent::__construct($message);
    }


    # Accessors
    public function getResponse() : ResponseInterface { return $this->m_response; }
}
