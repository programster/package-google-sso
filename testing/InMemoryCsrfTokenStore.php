<?php

/*
 * A CSRF token store that keeps the token in itself, standing in for a framework's session in the tests, to check that
 * the client uses whichever store it is given, and leaves $_SESSION alone.
 */

declare(strict_types = 1);

namespace Programster\GoogleSso\Testing;

use Programster\GoogleSso\CsrfTokenStoreInterface;


final class InMemoryCsrfTokenStore implements CsrfTokenStoreInterface
{
    private string $m_token = "";


    public function getToken() : string
    {
        return $this->m_token;
    }


    public function setToken(string $token) : void
    {
        $this->m_token = $token;
    }


    public function removeToken() : void
    {
        $this->m_token = "";
    }
}
