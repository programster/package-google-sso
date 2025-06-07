<?php

namespace Programster\GoogleSso\Exceptions;

class ExceptionCsrfTokenMismatch extends \Exception
{
    private string $googleResponseStateToken;
    private string $cachedStateToken;


    public function __construct(string $googleResponseStateToken, string $cachedStateToken)
    {
        $this->googleResponseStateToken = $googleResponseStateToken;
        $this->cachedStateToken = $cachedStateToken;
        parent::__construct("The CRSF token provided in the response did not match the one we created for the login request.");
    }


    public function getGoogleResponseToken() : string { return $this->googleResponseStateToken; }
    public function getCachedStateToken() : string { return $this->cachedStateToken; }
}