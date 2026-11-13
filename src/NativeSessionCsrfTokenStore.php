<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * Keeps the CSRF token in PHP's native session ($_SESSION), which is the default. The session must be started (with
 * session_start()) before the login URL is created, and before the callback is handled. If it hasn't been, every
 * method throws a \LogicException, rather than the token being put in a $_SESSION that is never saved, and the login
 * failing later for no apparent reason.
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


    /**
     * @throws \LogicException - if the session hasn't been started.
     */
    public function getToken() : string
    {
        self::assertSessionStarted();
        $hasToken = (isset($_SESSION[$this->m_key]) && is_string($_SESSION[$this->m_key]));
        return $hasToken ? $_SESSION[$this->m_key] : "";
    }


    /**
     * @throws \LogicException - if the session hasn't been started.
     */
    public function setToken(string $token) : void
    {
        self::assertSessionStarted();
        $_SESSION[$this->m_key] = $token;
    }


    /**
     * @throws \LogicException - if the session hasn't been started.
     */
    public function removeToken() : void
    {
        self::assertSessionStarted();
        unset($_SESSION[$this->m_key]);
    }


    /**
     * Check that PHP's session has been started, so that the token will be saved, and can be read back.
     * @return void
     * @throws \LogicException - if it hasn't been.
     */
    private static function assertSessionStarted() : void
    {
        if (session_status() !== PHP_SESSION_ACTIVE)
        {
            $message = (
                "PHP's session hasn't been started, so the CSRF token can't be kept. Call session_start() before"
                . " creating the login URL and handling the callback, or give GoogleSsoClient a csrfTokenStore that"
                . " uses your framework's session."
            );

            throw new \LogicException($message);
        }
    }


    # Accessors
    public function getKey() : string { return $this->m_key; }
}
