<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * Where the CSRF token (the OAuth "state") is kept for the current user, between them leaving for Google and coming
 * back. It must be kept per user, e.g. in their session, never in anything shared between users. The default,
 * NativeSessionCsrfTokenStore, keeps it in $_SESSION. Implement this to keep it in your framework's session instead.
 */
interface CsrfTokenStoreInterface
{
    /**
     * Get the current user's CSRF token.
     * @return string - the token, or an empty string if there isn't one.
     */
    public function getToken() : string;


    /**
     * Keep a CSRF token for the current user, replacing any they already have.
     * @param string $token - the token, which is never empty.
     * @return void
     */
    public function setToken(string $token) : void;


    /**
     * Remove the current user's CSRF token, if they have one, so that it can't be used again.
     * @return void
     */
    public function removeToken() : void;
}
