<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * Keeps the CSRF token in PHP's native session ($_SESSION), which is the default. The session must be started (with
 * session_start()) before the login URL is created, and before the callback is handled.
 */
final readonly class NativeSessionCsrfTokenStore implements CsrfTokenStoreInterface
{
    public const DEFAULT_KEY = "googleSsoCsrfToken";

    private string $m_key;


    /**
     * @param string $key - the $_SESSION key to keep the token under. Only change it if your app already uses that key.
     * @throws \InvalidArgumentException - if $key is empty.
     */
    public function __construct(string $key = self::DEFAULT_KEY)
    {
        if ($key === "")
        {
            throw new \InvalidArgumentException("The session key for the CSRF token can't be empty.");
        }

        $this->m_key = $key;
    }


    public function getToken() : string
    {
        $hasToken = (isset($_SESSION[$this->m_key]) && is_string($_SESSION[$this->m_key]));
        return $hasToken ? $_SESSION[$this->m_key] : "";
    }


    public function setToken(string $token) : void
    {
        $_SESSION[$this->m_key] = $token;
    }


    public function removeToken() : void
    {
        unset($_SESSION[$this->m_key]);
    }


    # Accessors
    public function getKey() : string { return $this->m_key; }
}
